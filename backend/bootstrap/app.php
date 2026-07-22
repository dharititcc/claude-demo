<?php

declare(strict_types=1);

use App\Http\Middleware\EnforcePlanLimit;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\InitializeTenancyForUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a load balancer the app sees the proxy's IP and http scheme
        // unless it trusts the X-Forwarded-* headers. Trusting them is what lets
        // URL::forceScheme('https') and SESSION_SECURE_COOKIE actually take
        // effect (the request must look https for a secure cookie to be sent).
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->alias([
            'tenant' => InitializeTenancyForUser::class,
            'active' => EnsureUserIsActive::class,
            'limit' => EnforcePlanLimit::class,
            'super-admin' => EnsureSuperAdmin::class,
        ]);

        // Stripe signs webhooks with its own secret and cannot present a CSRF
        // token; Cashier verifies the signature instead. The exclusion also
        // covers Laravel 13's added Sec-Fetch-Site origin check, which a
        // server-to-server call from Stripe would never satisfy — it sends no
        // such header.
        //
        // Renamed from validateCsrfTokens() in Laravel 13. The old name survives
        // as a deprecated alias; this is the real one.
        $middleware->preventRequestForgery(except: ['stripe/*']);

        // Order matters and is not obvious:
        //   Authenticate  → resolves the Sanctum token against the CENTRAL db
        //   tenant        → swaps the default connection to the tenant's db
        //   SubstituteBindings → resolves {customer} etc.
        //
        // Without this, route-model binding runs first and looks for tenant
        // tables (customers, …) in the central database.
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            InitializeTenancyForUser::class,
        );

        // The SPA authenticates with bearer tokens, so the CSRF-cookie flow is
        // not used; tokens are validated by `auth:sanctum`.
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api/* failure returns a predictable JSON envelope.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            [$status, $message] = match (true) {
                // Honour the exception's own status: login throttling raises a
                // ValidationException carrying 429, which clients must be able
                // to distinguish from a plain 422 validation failure.
                $e instanceof ValidationException => [
                    $e->status,
                    $e->status === 429 ? 'Too many attempts.' : 'The given data was invalid.',
                ],
                $e instanceof AuthenticationException => [401, 'Unauthenticated.'],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [404, 'Resource not found.'],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $e->getMessage() ?: 'Request failed.'],
                default => [500, 'Server error.'],
            };

            $payload = ['message' => $message];

            if ($e instanceof ValidationException) {
                $payload['errors'] = $e->errors();
            }

            // Never leak internals in production; surface them while debugging.
            if ($status === 500 && config('app.debug')) {
                $payload['exception'] = $e::class;
                $payload['detail'] = $e->getMessage();
            }

            return response()->json($payload, $status);
        });
    })->create();
