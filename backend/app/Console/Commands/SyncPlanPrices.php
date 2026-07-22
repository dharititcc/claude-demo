<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\StripePriceCatalogue;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Reconcile the plan catalogue's display amounts against Stripe.
 *
 * The amounts are validated and copied from Stripe when a plan is saved, but
 * prices can also change on the Stripe side — someone edits a price in the
 * dashboard, or archives one. Nothing tells the application that happened, and
 * the drift is invisible: the pricing page keeps advertising the old amount
 * while cards are charged the new one. This closes that window on a schedule.
 *
 * Reports rather than repairs where a repair would be a guess: an archived
 * price or a changed interval is surfaced as a warning, because deactivating
 * someone's live plan automatically is not a decision a cron job should take.
 */
class SyncPlanPrices extends Command
{
    protected $signature = 'plans:sync-stripe {--dry-run : Report the drift without writing anything}';

    protected $description = 'Refresh plan display amounts from Stripe and report prices that no longer match';

    public function handle(StripePriceCatalogue $prices): int
    {
        if (! $prices->configured()) {
            $this->warn('No Stripe secret configured — nothing to sync against.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $warnings = 0;

        foreach (Plan::orderBy('sort_order')->orderBy('id')->get() as $plan) {
            foreach ($this->intervals() as $priceKey => [$amountKey, $interval]) {
                $priceId = $plan->{$priceKey};

                if (blank($priceId)) {
                    continue;
                }

                try {
                    $price = $prices->retrieve((string) $priceId);
                } catch (RuntimeException $e) {
                    $this->error("{$plan->slug} ({$interval}): could not reach Stripe — {$e->getMessage()}");
                    $warnings++;

                    continue;
                }

                if ($price === null) {
                    $this->warn("{$plan->slug} ({$interval}): price {$priceId} no longer exists in Stripe.");
                    $warnings++;

                    continue;
                }

                if ($price->active === false) {
                    $this->warn("{$plan->slug} ({$interval}): price {$priceId} is archived and cannot be sold.");
                    $warnings++;
                }

                if ($price->recurring?->interval !== $interval) {
                    $this->warn("{$plan->slug} ({$interval}): price bills every ".($price->recurring->interval ?? 'one-off').', which no longer matches this field.');
                    $warnings++;
                }

                if (strcasecmp((string) $price->currency, (string) $plan->currency) !== 0) {
                    $this->warn("{$plan->slug} ({$interval}): price is in ".strtoupper((string) $price->currency).", but the plan is priced in {$plan->currency}.");
                    $warnings++;
                }

                // Tiered pricing has no single amount to display.
                if ($price->unit_amount === null) {
                    continue;
                }

                $current = (int) $plan->{$amountKey};
                $actual = (int) $price->unit_amount;

                if ($current === $actual) {
                    continue;
                }

                $rows[] = [$plan->slug, $interval, $this->money($current, $plan->currency), $this->money($actual, $plan->currency)];

                if (! $dryRun) {
                    $plan->forceFill([$amountKey => $actual])->save();
                }
            }
        }

        if ($rows === []) {
            $this->info('All plan amounts already match Stripe.'.($warnings > 0 ? " {$warnings} warning(s) above." : ''));

            return self::SUCCESS;
        }

        $this->table(['Plan', 'Interval', 'Was', 'Stripe'], $rows);
        $this->info($dryRun
            ? count($rows).' amount(s) would be updated. Re-run without --dry-run to apply.'
            : count($rows).' amount(s) updated from Stripe.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function intervals(): array
    {
        return [
            'stripe_monthly_price_id' => ['monthly_amount', 'month'],
            'stripe_annual_price_id' => ['annual_amount', 'year'],
        ];
    }

    private function money(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2).' '.strtoupper($currency);
    }
}
