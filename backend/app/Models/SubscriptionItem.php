<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;

/**
 * Pinned to central for the same reason as Subscription — see that model.
 */
class SubscriptionItem extends CashierSubscriptionItem
{
    use UsesCentralConnection;
}
