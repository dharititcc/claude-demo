<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| This is an API-only backend; the SPA (frontend/) is the user interface. The
| root returns a small JSON pointer rather than a Blade page, since the spec
| keeps Blade to email templates only.
|
*/

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
    'api' => url('/api/v1'),
    'docs' => url('/api/documentation'),
]));
