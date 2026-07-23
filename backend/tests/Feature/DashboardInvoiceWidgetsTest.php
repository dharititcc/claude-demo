<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * The invoice widgets added to the existing dashboard.
 *
 * No second dashboard: these extend the payload the customers dashboard already
 * returned, so a client that ignores them keeps working.
 */

/** @return array{0: string, 1: Tenant, 2: Customer} */
function dashboardFixture(string $email = 'dash-owner@example.test'): array
{
    // The dashboard caches for 5 minutes; each test needs its own figures.
    Cache::flush();

    [, $tenant, $token] = registerUser($email, 'Dashboard Co');
    $customer = $tenant->run(fn () => Customer::create(['name' => 'Acme Industries', 'status' => 'active']));

    return [$token, $tenant, $customer];
}

/** @param array<int, array<string, mixed>> $items */
function raiseInvoice(string $token, Tenant $tenant, Customer $customer, array $items, ?string $dueDate = null): int
{
    return apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", [
        'due_date' => $dueDate ?? now()->addDays(30)->toDateString(),
        'items' => $items,
    ])->assertCreated()->json('data.id');
}

it('reports invoiced, collected and outstanding totals', function () {
    [$token, $tenant, $customer] = dashboardFixture();

    $id = raiseInvoice($token, $tenant, $customer, [
        ['description' => 'Work', 'quantity' => 1, 'unit_price' => 300, 'tax_rate' => 0],
    ]);

    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/send")->assertOk();
    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/payments", ['amount' => 120])->assertOk();

    Cache::flush();

    $revenue = apiAs($token, $tenant)->getJson('/api/v1/dashboard')->assertOk()->json('data.revenue');

    expect($revenue['invoiced_total'])->toEqual(300)
        ->and($revenue['collected_total'])->toEqual(120)
        ->and($revenue['outstanding_total'])->toEqual(180);
});

it('excludes voided invoices from every total', function () {
    [$token, $tenant, $customer] = dashboardFixture();

    $kept = raiseInvoice($token, $tenant, $customer, [
        ['description' => 'Real work', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0],
    ]);
    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$kept}/send")->assertOk();

    $voided = raiseInvoice($token, $tenant, $customer, [
        ['description' => 'Raised in error', 'quantity' => 1, 'unit_price' => 900, 'tax_rate' => 0],
    ]);
    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$voided}/send")->assertOk();
    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$voided}/void")->assertOk();

    Cache::flush();

    $revenue = apiAs($token, $tenant)->getJson('/api/v1/dashboard')->assertOk()->json('data.revenue');

    // A cancelled document is not revenue and is not owed.
    expect($revenue['invoiced_total'])->toEqual(100)
        ->and($revenue['outstanding_total'])->toEqual(100);
});

it('counts overdue invoices from the clock, not a stored status', function () {
    [$token, $tenant, $customer] = dashboardFixture();

    $id = raiseInvoice($token, $tenant, $customer, [
        ['description' => 'Work', 'quantity' => 1, 'unit_price' => 250, 'tax_rate' => 0],
    ]);
    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/send")->assertOk();

    // Nothing writes to the row — the due date simply passes.
    $tenant->run(fn () => Invoice::find($id)->forceFill(['due_date' => now()->subWeek()])->save());

    Cache::flush();

    $revenue = apiAs($token, $tenant)->getJson('/api/v1/dashboard')->assertOk()->json('data.revenue');

    expect($revenue['overdue_count'])->toBe(1)
        ->and($revenue['overdue_total'])->toEqual(250);
});

it('ranks top customers by what they were actually invoiced', function () {
    [$token, $tenant, $small] = dashboardFixture();

    $big = $tenant->run(fn () => Customer::create([
        'name' => 'Big Spender',
        // A hand-typed lifetime_value must NOT decide the ranking.
        'lifetime_value' => 1,
    ]));

    $a = raiseInvoice($token, $tenant, $small, [['description' => 'Small', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0]]);
    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$a}/send")->assertOk();

    $b = raiseInvoice($token, $tenant, $big, [['description' => 'Large', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 0]]);
    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$b}/send")->assertOk();

    Cache::flush();

    $top = apiAs($token, $tenant)->getJson('/api/v1/dashboard')->assertOk()->json('data.top_customers');

    expect($top[0]['name'])->toBe('Big Spender')
        ->and($top[0]['invoiced'])->toEqual(5000)
        ->and($top[0]['customer_number'])->not->toBeNull();
});

it('still returns everything the dashboard returned before', function () {
    [$token, $tenant] = dashboardFixture();

    // Additive only: a client ignoring the new keys must keep working.
    apiAs($token, $tenant)->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'customers' => ['total', 'by_status', 'new_this_month'],
                'revenue' => ['lifetime_value', 'currency'],
                'growth',
                'organization' => ['name', 'status', 'on_trial'],
                'recent_customers',
            ],
        ]);
});
