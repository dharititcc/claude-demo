<?php

declare(strict_types=1);
use App\Models\Activity;

return [

    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    /*
     * Age after which log entries are deleted by `activitylog:clean`.
     */
    'delete_records_older_than_days' => 365,

    'default_log_name' => 'default',

    'default_auth_driver' => null,

    'subject_returns_soft_deleted_models' => false,

    /*
     * Our tenant-pinned model, so audit entries follow the tenancy connection
     * swap and land in the active organization's database.
     */
    'activity_model' => Activity::class,

    'table_name' => 'activity_log',

    /*
     * NULL uses the default connection, which under tenancy is the active
     * tenant's database — where the activity_log table lives. Setting a fixed
     * connection here would send every organization's audit trail to one place.
     */
    'database_connection' => null,
];
