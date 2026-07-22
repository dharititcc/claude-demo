<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| L5 Swagger — start from the package defaults, then lock the docs down.
|--------------------------------------------------------------------------
|
| The vendor default serves the Swagger UI (`api/documentation`) and the raw
| OpenAPI JSON (`docs`) with NO route middleware — i.e. world-readable in every
| environment. For this control-plane API that would advertise the entire
| internal surface (including the Super Admin and impersonation endpoints), so we
| require an authenticated super admin outside local. Local dev stays open for
| convenience. See config('l5-swagger.defaults.routes.middleware').
|
*/

$config = require base_path('vendor/darkaonline/l5-swagger/config/l5-swagger.php');

$gate = env('APP_ENV', 'production') === 'local'
    ? []
    : ['auth:sanctum', 'super-admin'];

$config['defaults']['routes']['middleware']['api'] = $gate;
$config['defaults']['routes']['middleware']['docs'] = $gate;

return $config;
