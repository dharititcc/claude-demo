<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;

/**
 * Customer invoicing: numbering, money arithmetic, status rules and access.
 *
 * Distinct from BillingTest, which covers what the organization pays us for the
 * platform. These are the organization's own sales documents.
 */

/** @return array{0: string, 1: Tenant, 2: Customer} */
function invoiceFixture(string $email = 'invoice-owner@example.test'): array
{
    [, $tenant, $token] = registerUser($email, 'Invoicing Co');

    $customer = $tenant->run(fn () => Customer::create(['name' => 'Acme Industries', 'currency' => 'USD']));

    return [$token, $tenant, $customer];
}

/** @param array<int, array<string, mixed>> $items */
function invoicePayload(array $items = [], array $overrides = []): array
{
    return array_merge([
        'due_date' => now()->addDays(30)->toDateString(),
        'items' => $items ?: [
            ['description' => 'Consulting', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 10],
        ],
    ], $overrides);
}

it('creates a draft invoice with a generated number and computed totals', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    $response = apiAs($token, $tenant)
        ->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())
        ->assertCreated();

    // 2 x 100 = 200 subtotal, 10% tax = 20, total 220.
    $response->assertJsonPath('data.number', 'INV-000001')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.subtotal', 200)
        ->assertJsonPath('data.tax_total', 20)
        ->assertJsonPath('data.total', 220)
        ->assertJsonPath('data.balance', 220);
});

it('numbers invoices sequentially', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())->assertCreated();

    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())
        ->assertCreated()
        ->assertJsonPath('data.number', 'INV-000002');
});

it('computes tax per line rather than on the invoice total', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    // Different rates per line: a single invoice-level rate would be wrong here.
    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload([
        ['description' => 'Taxed', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 20],
        ['description' => 'Zero rated', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.subtotal', 200)
        ->assertJsonPath('data.tax_total', 20)
        ->assertJsonPath('data.total', 220);
});

it('keeps totals exact on amounts that float arithmetic would drift', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    // 0.1 + 0.2 in floats is 0.30000000000000004; three lines of 0.10 must be
    // exactly 0.30 on a document somebody pays.
    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload([
        ['description' => 'A', 'quantity' => 1, 'unit_price' => 0.10],
        ['description' => 'B', 'quantity' => 1, 'unit_price' => 0.20],
        ['description' => 'C', 'quantity' => 3, 'unit_price' => 0.10],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.subtotal', 0.6)
        ->assertJsonPath('data.total', 0.6);
});

it('refuses to send an invoice with no lines', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    // Built through the model directly, bypassing the payload's items min:1.
    $invoice = $tenant->run(function () use ($customer) {
        $invoice = new Invoice;

        $invoice->forceFill([
            'customer_id' => $customer->id,
            'number' => 'INV-999999',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDay()->toDateString(),
        ])->save();

        return $invoice;
    });

    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$invoice->id}/send")
        ->assertStatus(422)
        ->assertJsonValidationErrors('items');
});

it('freezes the figures once an invoice is sent', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    $id = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())->json('data.id');

    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/send")
        ->assertOk()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.is_editable', false);

    // The customer holds a copy — restating the amounts would make our record
    // disagree with theirs.
    apiAs($token, $tenant)->putJson("/api/v1/invoices/{$id}", [
        'items' => [['description' => 'Cheaper', 'quantity' => 1, 'unit_price' => 1]],
    ])->assertStatus(422)->assertJsonValidationErrors('items');
});

it('still allows notes and due date to be amended after sending', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    $id = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())->json('data.id');
    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/send")->assertOk();

    apiAs($token, $tenant)->putJson("/api/v1/invoices/{$id}", [
        'notes' => 'Agreed an extension.',
        'due_date' => now()->addDays(60)->toDateString(),
    ])->assertOk()->assertJsonPath('data.notes', 'Agreed an extension.');
});

it('records a part payment without marking the invoice paid', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    $id = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())->json('data.id');
    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/send")->assertOk();

    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/payments", ['amount' => 100])
        ->assertOk()
        ->assertJsonPath('data.amount_paid', 100)
        ->assertJsonPath('data.balance', 120)
        ->assertJsonPath('data.status', 'sent')
        // Derived, not stored.
        ->assertJsonPath('data.display_status', 'partial')
        // Not stamped until the balance clears.
        ->assertJsonPath('data.paid_at', null);
});

it('marks the invoice paid only when the balance clears', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    $id = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())->json('data.id');
    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/send")->assertOk();

    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/payments", ['amount' => 100])->assertOk();

    $paid = apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/payments", ['amount' => 120])->assertOk();

    expect($paid->json('data.status'))->toBe('paid')
        ->and($paid->json('data.balance'))->toBe(0)
        ->and($paid->json('data.paid_at'))->not->toBeNull();
});

it('refuses a payment larger than the outstanding balance', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    $id = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())->json('data.id');

    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/payments", ['amount' => 500])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');
});

it('reports an unpaid invoice past its due date as overdue', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    $id = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())->json('data.id');
    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/send")->assertOk();

    // Push the due date into the past: nothing writes to the row, which is
    // exactly why the state is derived rather than stored.
    $tenant->run(fn () => Invoice::find($id)->forceFill(['due_date' => now()->subDay()])->save());

    apiAs($token, $tenant)->getJson("/api/v1/invoices/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.display_status', 'overdue')
        ->assertJsonPath('data.is_overdue', true);

    // And the filter finds it in SQL, so pagination stays correct.
    apiAs($token, $tenant)->getJson('/api/v1/invoices?status=overdue')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('voids an issued invoice but keeps its number', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    $id = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())->json('data.id');
    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/send")->assertOk();

    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/void")
        ->assertOk()
        ->assertJsonPath('data.status', 'void')
        ->assertJsonPath('data.number', 'INV-000001');
});

it('refuses to void a paid invoice', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    $id = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())->json('data.id');
    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/send")->assertOk();
    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/payments", ['amount' => 220])->assertOk();

    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/void")
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('refuses to delete an issued invoice, so the sequence keeps no gaps', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    $id = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())->json('data.id');

    // A draft was never issued, so it may go.
    $draft = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())->json('data.id');
    apiAs($token, $tenant)->deleteJson("/api/v1/invoices/{$draft}")->assertOk();

    apiAs($token, $tenant)->postJson("/api/v1/invoices/{$id}/send")->assertOk();
    apiAs($token, $tenant)->deleteJson("/api/v1/invoices/{$id}")->assertStatus(422);
});

it('never reuses a number, even after a draft is deleted', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    $draft = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())->json('data.id');
    apiAs($token, $tenant)->deleteJson("/api/v1/invoices/{$draft}")->assertOk();

    // INV-000001 has appeared somewhere; the next is 2, not 1 again.
    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())
        ->assertCreated()
        ->assertJsonPath('data.number', 'INV-000002');
});

it('rejects a client-supplied total or number', function () {
    [$token, $tenant, $customer] = invoiceFixture();

    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload(overrides: [
        'number' => 'INV-HACKED',
        'total' => 1,
        'status' => 'paid',
    ]))
        ->assertCreated()
        // All three ignored: they are not fillable and not in the request rules.
        ->assertJsonPath('data.number', 'INV-000001')
        ->assertJsonPath('data.total', 220)
        ->assertJsonPath('data.status', 'draft');
});

it('never exposes another organizations invoices', function () {
    [$tokenA, $tenantA, $customerA] = invoiceFixture('a@invoices.test');
    [$tokenB, $tenantB] = invoiceFixture('b@invoices.test');

    $id = apiAs($tokenA, $tenantA)->postJson("/api/v1/customers/{$customerA->id}/invoices", invoicePayload())->json('data.id');

    apiAs($tokenB, $tenantB)->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(0, 'data');
    apiAs($tokenB, $tenantB)->getJson("/api/v1/invoices/{$id}")->assertNotFound();
});

it('lets a viewer read invoices but not raise one', function () {
    [$token, $tenant, $customer] = invoiceFixture('viewer@invoices.test');
    $user = User::where('email', 'viewer@invoices.test')->firstOrFail();

    giveRole($tenant, $user, 'viewer');

    apiAs($token, $tenant)->getJson('/api/v1/invoices')->assertOk();

    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/invoices", invoicePayload())
        ->assertForbidden();
});
