<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\StripePriceCatalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use Stripe\Price;
use Stripe\Product;

/**
 * Fill in plan price ids from the Stripe catalogue.
 *
 * Creating a product in Stripe does not wire it to anything here — the id has to
 * reach `plans`, and doing that by hand is six copy-pastes with an easy way to
 * put an annual id in a monthly field. This matches Stripe's products to our
 * plans and fills both intervals in.
 *
 * Matching is by slug: a product's `metadata.plan_slug` if set, otherwise its
 * name slugified. Anything that does not match is reported rather than guessed
 * at, and no plan is ever created — limits, features and trial length have no
 * Stripe equivalent, so a plan invented here would silently be unlimited.
 */
class ImportStripePrices extends Command
{
    protected $signature = 'plans:import-stripe
        {--dry-run : Show what would be imported without writing}
        {--force : Overwrite price ids that are already set}';

    protected $description = 'Match Stripe products to plans and import their price ids';

    /** @var array<string, string> Stripe interval => plan column prefix. */
    private const INTERVALS = ['month' => 'monthly', 'year' => 'annual'];

    public function handle(StripePriceCatalogue $catalogue): int
    {
        if (! $catalogue->configured()) {
            $this->error('No Stripe secret configured — nothing to import from.');

            return self::FAILURE;
        }

        try {
            $prices = $catalogue->activeRecurringPrices();
        } catch (RuntimeException $e) {
            $this->error("Could not reach Stripe: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($prices === []) {
            $this->warn('Stripe has no active recurring prices. Create your products first.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $byProduct = $this->groupByProduct($prices);
        $rows = [];
        $unmatched = [];

        foreach ($byProduct as ['slug' => $slug, 'name' => $name, 'prices' => $productPrices]) {
            $plan = Plan::where('slug', $slug)->first();

            if ($plan === null) {
                $unmatched[] = "{$name} (looked for plan slug '{$slug}')";

                continue;
            }

            foreach (self::INTERVALS as $interval => $prefix) {
                $rows[] = $this->importInterval($plan, $productPrices, $interval, $prefix, $force, $dryRun);
            }

            if (! $dryRun) {
                $plan->save();
            }
        }

        $this->report(array_filter($rows), $unmatched, $dryRun);

        return self::SUCCESS;
    }

    /**
     * One interval of one plan. Returns a report row, or null when there was
     * nothing in Stripe for it.
     *
     * @param array<int, Price> $prices
     * @return array<int, string>|null
     */
    private function importInterval(Plan $plan, array $prices, string $interval, string $prefix, bool $force, bool $dryRun): ?array
    {
        $column = "stripe_{$prefix}_price_id";

        // Only prices that bill every 1 of this interval: "every 3 months" is a
        // month price but not a monthly plan.
        $candidates = array_values(array_filter(
            $prices,
            fn (Price $p) => $p->recurring?->interval === $interval
                && ($p->recurring->interval_count ?? 1) === 1,
        ));

        if ($candidates === []) {
            return null;
        }

        // Currency has to agree with the plan, or checkout would be selling in
        // one currency and advertising in another.
        $matching = array_values(array_filter(
            $candidates,
            fn (Price $p) => strcasecmp((string) $p->currency, (string) $plan->currency) === 0,
        ));

        if ($matching === []) {
            $found = strtoupper((string) $candidates[0]->currency);

            return [$plan->slug, $prefix, '—', '—', "skipped: prices are in {$found}, plan is {$plan->currency}"];
        }

        if (count($matching) > 1) {
            // Two equally valid prices; picking one would be a coin toss that
            // silently decides what customers pay.
            return [$plan->slug, $prefix, '—', '—', 'skipped: '.count($matching).' candidate prices — set metadata.plan_slug or archive one'];
        }

        $price = $matching[0];
        $current = (string) $plan->{$column};

        if ($current === $price->id) {
            return [$plan->slug, $prefix, $price->id, $this->money($price), 'already correct'];
        }

        if ($current !== '' && ! $force) {
            return [$plan->slug, $prefix, $current, '—', 'kept: already set (use --force to replace)'];
        }

        $amountColumn = "{$prefix}_amount";
        $plan->{$column} = $price->id;

        // Tiered pricing has no single amount to display; leave what is there.
        if ($price->unit_amount !== null) {
            $plan->{$amountColumn} = (int) $price->unit_amount;
        }

        return [$plan->slug, $prefix, $price->id, $this->money($price), $dryRun ? 'would import' : 'imported'];
    }

    /**
     * Group prices by their product, resolving each product to a plan slug.
     *
     * @param array<int, Price> $prices
     * @return array<string, array{slug: string, name: string, prices: array<int, Price>}>
     */
    private function groupByProduct(array $prices): array
    {
        $grouped = [];

        foreach ($prices as $price) {
            $product = $price->product;

            // Unexpanded products arrive as a bare id; nothing to match on.
            if (! $product instanceof Product) {
                continue;
            }

            $slug = $this->slugFor($product);

            $grouped[$product->id] ??= ['slug' => $slug, 'name' => (string) $product->name, 'prices' => []];
            $grouped[$product->id]['prices'][] = $price;
        }

        return $grouped;
    }

    /**
     * The plan slug a Stripe product refers to.
     *
     * `metadata.plan_slug` is the explicit way to say so and wins; otherwise the
     * product name is slugified, which is why a product called "Starter" finds
     * the `starter` plan without any setup.
     */
    private function slugFor(Product $product): string
    {
        $explicit = $product->metadata['plan_slug'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return Str::slug((string) $product->name);
    }

    private function money(Price $price): string
    {
        if ($price->unit_amount === null) {
            return 'tiered';
        }

        return number_format($price->unit_amount / 100, 2).' '.strtoupper((string) $price->currency);
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @param array<int, string> $unmatched
     */
    private function report(array $rows, array $unmatched, bool $dryRun): void
    {
        if ($rows !== []) {
            $this->table(['Plan', 'Interval', 'Price', 'Amount', 'Result'], $rows);
        }

        foreach ($unmatched as $product) {
            $this->warn("No plan matches Stripe product: {$product}");
        }

        $imported = count(array_filter($rows, fn (array $r) => str_starts_with($r[4], 'would import') || $r[4] === 'imported'));

        if ($imported === 0) {
            $this->info('Nothing to import — every matched plan is already wired up.');

            return;
        }

        $this->info($dryRun
            ? "{$imported} price id(s) would be imported. Re-run without --dry-run to apply."
            : "{$imported} price id(s) imported.");
    }
}
