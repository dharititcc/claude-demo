<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Records create/update/delete on a model to the tenant's activity log.
 *
 * The log table lives in each tenant database (see the tenant migrations), so an
 * audit trail is naturally scoped to one organization and isolated from every
 * other — the same guarantee the rest of the tenant data has.
 *
 * A model using this trait must declare `protected array $auditable` listing the
 * attributes worth recording. Everything else (timestamps, denormalised
 * counters, positions) is noise in an audit trail and is excluded.
 *
 * @property array<int, string> $auditable
 */
trait Auditable
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->auditable)
            // Record only what actually changed, so an update that touches one
            // field does not log the entire row as "changed".
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName($this->getTable());
    }

    /**
     * A human-readable description, e.g. "customer created".
     */
    public function getDescriptionForEvent(string $eventName): string
    {
        return str($this->getTable())->singular()->append(" {$eventName}")->toString();
    }
}
