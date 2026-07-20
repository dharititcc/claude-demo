<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Laravel 12's base controller is empty by default. Authorization is opt-in
     * here so controllers can call authorize()/authorizeResource() and delegate
     * to the policies.
     */
    use AuthorizesRequests;
}
