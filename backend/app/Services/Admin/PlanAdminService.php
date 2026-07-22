<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\StripePriceCatalogue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The plan catalogue, as maintained by a Super Admin.
 *
 * Plans are central and platform-wide, so nothing here enters a tenant
 * database. The one piece of real domain logic is the delete guard — see
 * delete().
 */
class PlanAdminService
{
    public function __construct(private readonly StripePriceCatalogue $prices) {}

    /**
     * The whole catalogue, including inactive plans: an administrator manages
     * them, unlike the customer-facing list which only shows what can be bought.
     *
     * @return Collection<int, Plan>
     */
    public function catalogue(): Collection
    {
        return Plan::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * How many organizations sit on each plan, in one grouped query rather than
     * one per plan.
     *
     * Soft-deleted organizations are counted: they still point at the plan and
     * can be restored, so the plan is not genuinely unused.
     *
     * @return array<int, int> Keyed by plan id.
     */
    public function subscriberCounts(): array
    {
        return Tenant::withTrashed()
            ->whereNotNull('plan_id')
            ->groupBy('plan_id')
            ->selectRaw('plan_id, count(*) as aggregate')
            ->pluck('aggregate', 'plan_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Plan
    {
        $data = $this->withStripeAmounts($data);

        return DB::transaction(function () use ($data) {
            $data['slug'] = $this->slugFor($data['slug'] ?? null, (string) $data['name']);

            // Append rather than landing on 0 alongside everything else, so a
            // new plan shows up at the end of the catalogue by default.
            $data['sort_order'] ??= ((int) Plan::max('sort_order')) + 1;

            return Plan::create($data);
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Plan $plan, array $data): Plan
    {
        $data = $this->withStripeAmounts($data, $plan);

        return DB::transaction(function () use ($plan, $data) {
            $plan->fill($data)->save();

            return $plan->refresh();
        });
    }

    /**
     * Replace the submitted display amounts with what Stripe will actually
     * charge.
     *
     * Stripe is the source of truth for money, so a hand-typed amount is at
     * best a guess and at worst a lie — a plan advertising $29 whose Stripe
     * price charges $39 is invisible until a card is billed. Whenever an
     * interval has a price id, its amount is taken from that price instead of
     * from the form.
     *
     * The price was already fetched by the validation rule, so the catalogue
     * answers from its memo rather than calling Stripe a second time.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withStripeAmounts(array $data, ?Plan $existing = null): array
    {
        if (! $this->prices->configured()) {
            return $data;
        }

        $intervals = [
            'stripe_monthly_price_id' => 'monthly_amount',
            'stripe_annual_price_id' => 'annual_amount',
        ];

        foreach ($intervals as $priceKey => $amountKey) {
            // A partial edit may not resend the price id; fall back to the one
            // already stored, so editing the name still reconciles the amount.
            $priceId = array_key_exists($priceKey, $data)
                ? $data[$priceKey]
                : $existing?->{$priceKey};

            if (blank($priceId)) {
                continue;
            }

            try {
                $price = $this->prices->retrieve((string) $priceId);
            } catch (RuntimeException) {
                continue; // unreachable; leave the submitted value alone
            }

            // unit_amount is null for tiered pricing, which has no single
            // amount to display — leave whatever was submitted.
            if ($price?->unit_amount !== null) {
                $data[$amountKey] = (int) $price->unit_amount;
            }
        }

        return $data;
    }

    /**
     * Remove a plan from the catalogue.
     *
     * Refuses while organizations are still on it. `tenants.plan_id` carries no
     * foreign key, so the delete would succeed and leave those rows pointing at
     * a plan that no longer exists — and UsageService::planFor() silently falls
     * back to the cheapest active plan, so the organizations would be re-quotaed
     * without anybody being told. Deactivating is the reversible alternative:
     * an inactive plan disappears from the buyable list while existing
     * subscribers keep their limits.
     *
     * @throws ValidationException
     */
    public function delete(Plan $plan): void
    {
        $inUse = $this->subscriberCounts()[$plan->id] ?? 0;

        if ($inUse > 0) {
            throw ValidationException::withMessages([
                'plan' => __('This plan cannot be deleted: :count organization(s) are on it. Deactivate it instead — it will stop being offered while current subscribers keep their limits.', [
                    'count' => $inUse,
                ]),
            ]);
        }

        DB::transaction(fn () => $plan->delete());
    }

    /**
     * Use the given slug, or derive a unique one from the name.
     *
     * The derived path has to uniquify itself: validation only checked the slug
     * the caller supplied, so two plans named the same would otherwise collide
     * on the unique index.
     */
    private function slugFor(?string $slug, string $name): string
    {
        if (filled($slug)) {
            return $slug;
        }

        $base = Str::slug($name) ?: 'plan';
        $candidate = $base;
        $suffix = 2;

        while (Plan::where('slug', $candidate)->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
