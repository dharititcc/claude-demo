<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * An organization's subscription.
 *
 * Pinned to the central connection for the same reason as User and
 * PersonalAccessToken: billing lives centrally, and this model is read while a
 * tenant connection may be active (the billing page runs inside tenant context),
 * where the `subscriptions` table does not exist.
 *
 * @property string $tenant_id
 * @property Carbon|null $current_period_end
 */
class Subscription extends CashierSubscription
{
    use UsesCentralConnection;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'current_period_end' => 'datetime',
        ]);
    }
}
