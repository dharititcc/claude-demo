<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe Price IDs
    |--------------------------------------------------------------------------
    |
    | Price ids differ between Stripe's test and live catalogues, so they come
    | from the environment rather than being hard-coded — pointing production at
    | test prices (or the reverse) is a silent, expensive mistake.
    |
    | They are read here, in a config file, rather than via env() at the point of
    | use: once `config:cache` runs (as it should in production), env() returns
    | null everywhere outside config. Reading them in the seeder directly would
    | mean every paid plan silently loses its price id in production while
    | working perfectly in development.
    |
    */

    'prices' => [
        'starter' => [
            'monthly' => env('STRIPE_PRICE_STARTER_MONTHLY'),
            'annual' => env('STRIPE_PRICE_STARTER_ANNUAL'),
        ],
        'pro' => [
            'monthly' => env('STRIPE_PRICE_PRO_MONTHLY'),
            'annual' => env('STRIPE_PRICE_PRO_ANNUAL'),
        ],
        'enterprise' => [
            'monthly' => env('STRIPE_PRICE_ENTERPRISE_MONTHLY'),
            'annual' => env('STRIPE_PRICE_ENTERPRISE_ANNUAL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trial
    |--------------------------------------------------------------------------
    |
    | Days of trial granted when an organization is created, before any plan is
    | chosen. Per-plan trials are configured on the plan record itself.
    |
    */

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

];
