<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A subscription plan. Central: plans are platform-wide.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $stripe_monthly_price_id
 * @property string|null $stripe_annual_price_id
 * @property int $monthly_amount
 * @property int $annual_amount
 * @property string $currency
 * @property int $trial_days
 * @property int|null $max_users
 * @property int|null $max_customers
 * @property int|null $max_storage_mb
 * @property array<int, string>|null $features
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Plan extends Model
{
    use UsesCentralConnection;

    /** Billing intervals a plan can be bought on. */
    public const INTERVALS = ['monthly', 'annual'];

    /** @var list<string> */
    protected $fillable = [
        'name', 'slug', 'description',
        'stripe_monthly_price_id', 'stripe_annual_price_id',
        'monthly_amount', 'annual_amount', 'currency', 'trial_days',
        'max_users', 'max_customers', 'max_storage_mb',
        'features', 'is_active', 'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_active' => 'boolean',
            'monthly_amount' => 'integer',
            'annual_amount' => 'integer',
            'trial_days' => 'integer',
            'max_users' => 'integer',
            'max_customers' => 'integer',
            'max_storage_mb' => 'integer',
        ];
    }

    /**
     * The Stripe price id for an interval — what actually gets charged.
     */
    public function priceIdFor(string $interval): ?string
    {
        return match ($interval) {
            'annual' => $this->stripe_annual_price_id,
            default => $this->stripe_monthly_price_id,
        };
    }

    /**
     * Display amount in minor units (cents). Not used for charging.
     */
    public function amountFor(string $interval): int
    {
        return $interval === 'annual' ? $this->annual_amount : $this->monthly_amount;
    }

    /**
     * A limit of NULL means unlimited, which is deliberately different from 0
     * ("none allowed"). Callers must not conflate the two.
     */
    public function limitFor(string $key): ?int
    {
        return match ($key) {
            'users' => $this->max_users,
            'customers' => $this->max_customers,
            'storage_mb' => $this->max_storage_mb,
            default => null,
        };
    }

    /**
     * @param Builder<Plan> $query
     * @return Builder<Plan>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
