<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the Super Admin dashboard/list numbers fresh. Queues one job per tenant
// (see the command), so the run scales with the number of organizations rather
// than lengthening one process. withoutOverlapping guards a slow run from being
// re-entered by the next tick.
Schedule::command('app:refresh-org-stats')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Sanctum tokens carry an expiration (config/sanctum.php); expired rows are
// dead weight once past it. Prune the ones expired more than a day ago so the
// personal_access_tokens table doesn't grow without bound.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
