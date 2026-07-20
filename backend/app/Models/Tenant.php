<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Billable;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A Tenant is an Organization. Each has its own database, provisioned
 * automatically on creation (see TenancyServiceProvider events).
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $phone
 * @property string|null $logo
 * @property string $timezone
 * @property string $currency
 * @property string $language
 * @property string $status
 * @property int|null $plan_id
 * @property array<string, int|null>|null $limit_overrides
 * @property Carbon|null $trial_ends_at
 * @property string|null $stripe_id
 * @property string|null $pm_type
 * @property string|null $pm_last_four
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, User> $owners
 * @property-read Plan|null $plan
 * @property-read OrganizationStat|null $stats
 * @property-read int|null $members_count
 * @property-read OrganizationUser|null $pivot Present when loaded through User::organizations()
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    // The organization is the billable entity, not the user: a person in several
    // organizations must not carry several payment methods, and a subscription
    // must survive its purchaser leaving the company.
    use Billable;
    use HasDatabase;
    use HasDomains;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * Real (non-virtual) columns. Everything else is stored in the JSON `data`
     * column by stancl's VirtualColumn trait.
     *
     * @return array<int, string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'slug',
            'phone',
            'logo',
            'timezone',
            'currency',
            'language',
            'status',
            'plan_id',
            'limit_overrides',
            'trial_ends_at',

            // deleted_at MUST be listed, or SoftDeletes breaks on restore.
            // VirtualColumn funnels every undeclared attribute into the `data`
            // JSON on save(); a soft *delete* survives only because it is a
            // direct query update, but restore() sets deleted_at = null and
            // saves through the model — so without this line the real column is
            // never cleared and a "restored" org stays invisible forever.
            'deleted_at',

            // Cashier's billing columns MUST be listed here. stancl's
            // VirtualColumn trait funnels any undeclared attribute into the
            // `data` JSON blob — Cashier would then write stripe_id into JSON
            // while its `where('stripe_id', …)` lookups queried a real column,
            // silently never matching and re-creating a Stripe customer on
            // every request.
            'stripe_id',
            'pm_type',
            'pm_last_four',
        ];
    }

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'limit_overrides' => 'array',
        ];
    }

    /**
     * Members of this organization (central users). Their role is resolved from
     * this tenant's own database rather than from a column here.
     *
     * @return BelongsToMany<User, $this, OrganizationUser, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user', 'tenant_id', 'user_id')
            ->using(OrganizationUser::class)
            ->withPivot('is_owner')
            ->withTimestamps();
    }

    /**
     * The organization's owner(s) — members flagged `is_owner` on the pivot.
     *
     * Modelled as a collection rather than a single relation because the pivot
     * permits more than one owner (co-founders, ownership handover mid-transfer).
     * The admin list reads the first; nothing here assumes exactly one.
     *
     * @return BelongsToMany<User, $this, OrganizationUser, 'pivot'>
     */
    public function owners(): BelongsToMany
    {
        return $this->members()->wherePivot('is_owner', true);
    }

    /**
     * The subscription plan, when one has been assigned. Both models live in the
     * central database, so this is a plain central-to-central relation.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The denormalised statistics rollup (central), refreshed from this org's
     * tenant database by RefreshOrganizationStats. Absent until the first
     * rollup runs — the admin screens treat a missing row as "not yet measured".
     *
     * @return HasOne<OrganizationStat, $this>
     */
    public function stats(): HasOne
    {
        return $this->hasOne(OrganizationStat::class, 'tenant_id');
    }

    public function isOnTrial(): bool
    {
        return $this->status === 'trial'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /**
     * A trial that has lapsed without converting to a paid plan — the "expired"
     * bucket on the admin dashboard. Derived, because it is not a stored status:
     * the row still says `trial`; only the clock has moved.
     */
    public function isTrialExpired(): bool
    {
        return $this->status === 'trial'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast();
    }
}
