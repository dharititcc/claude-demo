<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Intentionally empty.
|
| This application does NOT use domain- or subdomain-based tenancy. A tenant is
| identified by the `X-Organization` header on the API, resolved by the
| `tenant` middleware (App\Http\Middleware\InitializeTenancyForUser) in
| routes/api.php. Tenant-scoped endpoints therefore live in routes/api.php, not
| here.
|
| The stancl scaffolding registers a domain-identified `/` route by default;
| left in place it collides with the application root and throws
| TenantCouldNotBeIdentifiedOnDomainException for any host that is not a
| registered tenant domain. It is removed deliberately.
|
*/
