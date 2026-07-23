<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\File;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Customer documents: the seam over the Files module, and the version chain.
 */

/** @return array{0: string, 1: Tenant, 2: Customer} */
function documentFixture(string $email = 'docs-owner@example.test'): array
{
    Storage::fake('public');

    [, $tenant, $token] = registerUser($email, 'Docs Co');
    $customer = $tenant->run(fn () => Customer::create(['name' => 'Acme Industries']));

    return [$token, $tenant, $customer];
}

it('uploads a document against a customer', function () {
    [$token, $tenant, $customer] = documentFixture();

    apiAs($token, $tenant)
        ->postJson("/api/v1/customers/{$customer->id}/documents", [
            'file' => UploadedFile::fake()->create('contract.pdf', 40, 'application/pdf'),
            'category' => 'contract',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'contract.pdf')
        ->assertJsonPath('data.category', 'contract')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.is_current', true)
        ->assertJsonPath('data.is_previewable', true);
});

it('reuses the file manager block-list, so a script cannot be filed', function () {
    [$token, $tenant, $customer] = documentFixture();

    // The deny-list lives in FileManagerService and is not restated per route.
    apiAs($token, $tenant)
        ->postJson("/api/v1/customers/{$customer->id}/documents", [
            'file' => UploadedFile::fake()->create('shell.php', 2, 'text/plain'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');
});

it('rejects a category outside the allow-list', function () {
    [$token, $tenant, $customer] = documentFixture();

    apiAs($token, $tenant)
        ->postJson("/api/v1/customers/{$customer->id}/documents", [
            'file' => UploadedFile::fake()->create('note.pdf', 5, 'application/pdf'),
            'category' => 'not-a-category',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('category');
});

it('keeps the previous version when a document is replaced', function () {
    [$token, $tenant, $customer] = documentFixture();

    $first = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/documents", [
        'file' => UploadedFile::fake()->create('terms.pdf', 10, 'application/pdf'),
        'category' => 'contract',
    ])->json('data.id');

    $second = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/documents/{$first}/replace", [
        'file' => UploadedFile::fake()->create('terms-v2.pdf', 12, 'application/pdf'),
    ])
        ->assertCreated()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.replaces_id', $first)
        // Inherited, not re-supplied: a replacement must not re-file the document.
        ->assertJsonPath('data.category', 'contract')
        ->json('data.id');

    // Nothing is destroyed — the old row is the history.
    $tenant->run(function () use ($first, $second) {
        expect(File::find($first))->not->toBeNull()
            ->and(File::find($second)->replaces_id)->toBe($first);
    });
});

it('lists only the current version', function () {
    [$token, $tenant, $customer] = documentFixture();

    $first = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/documents", [
        'file' => UploadedFile::fake()->create('terms.pdf', 10, 'application/pdf'),
    ])->json('data.id');

    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/documents/{$first}/replace", [
        'file' => UploadedFile::fake()->create('terms-v2.pdf', 12, 'application/pdf'),
    ])->assertCreated();

    $listed = apiAs($token, $tenant)->getJson("/api/v1/customers/{$customer->id}/documents")
        ->assertOk()
        ->json('data');

    // Two rows exist; only the newest is "current".
    expect($listed)->toHaveCount(1)
        ->and($listed[0]['version'])->toBe(2);
});

it('returns the whole version chain, from any link in it', function () {
    [$token, $tenant, $customer] = documentFixture();

    $v1 = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/documents", [
        'file' => UploadedFile::fake()->create('terms.pdf', 10, 'application/pdf'),
    ])->json('data.id');

    $v2 = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/documents/{$v1}/replace", [
        'file' => UploadedFile::fake()->create('terms-v2.pdf', 12, 'application/pdf'),
    ])->json('data.id');

    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/documents/{$v2}/replace", [
        'file' => UploadedFile::fake()->create('terms-v3.pdf', 14, 'application/pdf'),
    ])->assertCreated();

    // Asked from the OLDEST version — the history is the same either way.
    $versions = apiAs($token, $tenant)->getJson("/api/v1/customers/{$customer->id}/documents/{$v1}/versions")
        ->assertOk()
        ->json('data');

    expect(array_column($versions, 'version'))->toBe([3, 2, 1]);
});

it('filters documents by category', function () {
    [$token, $tenant, $customer] = documentFixture();

    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/documents", [
        'file' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'),
        'category' => 'contract',
    ])->assertCreated();

    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/documents", [
        'file' => UploadedFile::fake()->create('b.pdf', 5, 'application/pdf'),
        'category' => 'report',
    ])->assertCreated();

    $contracts = apiAs($token, $tenant)
        ->getJson("/api/v1/customers/{$customer->id}/documents?category=contract")
        ->assertOk()->json('data');

    expect($contracts)->toHaveCount(1)
        ->and($contracts[0]['category'])->toBe('contract');
});

it('refuses to reach a document through another customer', function () {
    [$token, $tenant, $customer] = documentFixture();
    $other = $tenant->run(fn () => Customer::create(['name' => 'Someone Else']));

    $id = apiAs($token, $tenant)->postJson("/api/v1/customers/{$other->id}/documents", [
        'file' => UploadedFile::fake()->create('private.pdf', 5, 'application/pdf'),
    ])->json('data.id');

    // Same tenant, so tenancy alone does not stop this — the nested lookup does.
    apiAs($token, $tenant)
        ->getJson("/api/v1/customers/{$customer->id}/documents/{$id}/versions")
        ->assertNotFound();

    apiAs($token, $tenant)
        ->postJson("/api/v1/customers/{$customer->id}/documents/{$id}/replace", [
            'file' => UploadedFile::fake()->create('swap.pdf', 5, 'application/pdf'),
        ])
        ->assertNotFound();
});

it('marks an unexpected type as not previewable', function () {
    [$token, $tenant, $customer] = documentFixture();

    // Allow-list, not block-list: anything unrecognised is downloaded, because
    // rendering it inline is how a document becomes a stored-XSS vector.
    apiAs($token, $tenant)
        ->postJson("/api/v1/customers/{$customer->id}/documents", [
            'file' => UploadedFile::fake()->create('data.csv', 5, 'text/csv'),
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_previewable', false);
});

it('requires files.upload to file a document', function () {
    [$user, $tenant, $token] = registerUser('viewer@docs.test', 'Viewer Docs');
    Storage::fake('public');
    $customer = $tenant->run(fn () => Customer::create(['name' => 'Acme']));

    giveRole($tenant, $user, 'viewer');

    apiAs($token, $tenant)->getJson("/api/v1/customers/{$customer->id}/documents")->assertOk();

    apiAs($token, $tenant)
        ->postJson("/api/v1/customers/{$customer->id}/documents", [
            'file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'),
        ])
        ->assertForbidden();
});
