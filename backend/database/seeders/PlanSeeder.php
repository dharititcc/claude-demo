<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Seeds the platform's plans into the central database.
 *
 * Stripe price ids are read from config/billing.php, not env() directly: once
 * `config:cache` runs in production, env() returns null outside config, which
 * would silently strip the price id from every paid plan.
 *
 * Without a price id a plan still works for limits and display — it simply
 * cannot be subscribed to until the id is configured.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'For trying things out.',
                'monthly_amount' => 0,
                'annual_amount' => 0,
                'trial_days' => 0,
                'max_users' => 2,
                'max_customers' => 25,
                'max_storage_mb' => 100,
                'features' => ['Up to 2 users', '25 customers', '100 MB storage', 'Community support'],
                'sort_order' => 1,
            ],
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'For small teams getting going.',
                'stripe_monthly_price_id' => config('billing.prices.starter.monthly'),
                'stripe_annual_price_id' => config('billing.prices.starter.annual'),
                'monthly_amount' => 2900,
                'annual_amount' => 29000, // two months free
                'trial_days' => 14,
                'max_users' => 10,
                'max_customers' => 1_000,
                'max_storage_mb' => 5_000,
                'features' => ['Up to 10 users', '1,000 customers', '5 GB storage', 'Email support', 'CSV import & export'],
                'sort_order' => 2,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'For growing businesses.',
                'stripe_monthly_price_id' => config('billing.prices.pro.monthly'),
                'stripe_annual_price_id' => config('billing.prices.pro.annual'),
                'monthly_amount' => 9900,
                'annual_amount' => 99000,
                'trial_days' => 14,
                'max_users' => 50,
                'max_customers' => 25_000,
                'max_storage_mb' => 50_000,
                'features' => ['Up to 50 users', '25,000 customers', '50 GB storage', 'Priority support', 'Audit logs'],
                'sort_order' => 3,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Unlimited scale.',
                'stripe_monthly_price_id' => config('billing.prices.enterprise.monthly'),
                'stripe_annual_price_id' => config('billing.prices.enterprise.annual'),
                'monthly_amount' => 29900,
                'annual_amount' => 299000,
                'trial_days' => 30,
                // NULL, not 0 — unlimited rather than none allowed.
                'max_users' => null,
                'max_customers' => null,
                'max_storage_mb' => null,
                'features' => ['Unlimited users', 'Unlimited customers', 'Unlimited storage', 'SSO', 'Dedicated support'],
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $plan) {
            // Idempotent: re-seeding refreshes limits/copy without duplicating.
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
