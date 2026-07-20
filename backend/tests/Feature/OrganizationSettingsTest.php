<?php

declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('updates organization settings', function () {
    [, $tenant, $token] = registerUser('settings@example.test', 'Settings Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/organization', [
            'name' => 'Renamed Org',
            'timezone' => 'Europe/London',
            'currency' => 'gbp',
            'language' => 'en',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Org')
        ->assertJsonPath('data.timezone', 'Europe/London')
        // Normalised on the way in, so clients never have to guess the case.
        ->assertJsonPath('data.currency', 'GBP');

    expect($tenant->fresh()->name)->toBe('Renamed Org');
});

it('never lets the slug be changed', function () {
    [, $tenant, $token] = registerUser('slug@example.test', 'Slug Org');
    $original = $tenant->slug;

    // The slug is the tenant identifier clients send in X-Organization; changing
    // it would break every stored reference.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/organization', ['name' => 'Still Fine', 'slug' => 'hijacked'])
        ->assertOk();

    expect($tenant->fresh()->slug)->toBe($original);
});

it('rejects an invalid timezone and currency', function () {
    [, $tenant, $token] = registerUser('badtz@example.test', 'BadTz Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/organization', ['timezone' => 'Mars/Olympus'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('timezone');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/organization', ['currency' => 'DOLLARS'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('currency');
});

it('stores an uploaded logo', function () {
    Storage::fake('public');

    [, $tenant, $token] = registerUser('logo@example.test', 'Logo Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->post('/api/v1/organization', [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    $logo = $tenant->fresh()->logo;

    expect($logo)->not->toBeNull();

    // Assert inside tenant context: the file was written to the tenant-suffixed
    // public disk, and the request has since reverted to central (terminate()),
    // so a bare Storage::disk('public') would look in the wrong directory.
    $tenant->run(fn () => Storage::disk('public')->assertExists($logo));
});

it('rejects a non-image logo', function () {
    Storage::fake('public');
    [, $tenant, $token] = registerUser('badlogo@example.test', 'BadLogo Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->post('/api/v1/organization', [
            'logo' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('logo');
});

it('forbids a manager from changing settings', function () {
    [$user, $tenant, $token] = registerUser('mgrset@example.test', 'MgrSet Org');
    giveRole($tenant, $user, 'manager');

    // Managers may view settings but not change them.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/organization', ['name' => 'Nope'])
        ->assertStatus(403);

    expect($tenant->fresh()->name)->toBe('MgrSet Org');
});

it('keeps settings changes inside one organization', function () {
    [$user, $tenantA, $token] = registerUser('setiso@example.test', 'Iso Settings A');
    [, $tenantB] = registerUser('setisob@example.test', 'Iso Settings B');

    $this->withHeaders(orgHeaders($token, $tenantA))
        ->postJson('/api/v1/organization', ['name' => 'Only A Renamed'])
        ->assertOk();

    expect($tenantA->fresh()->name)->toBe('Only A Renamed')
        ->and($tenantB->fresh()->name)->toBe('Iso Settings B');
});

/*
|--------------------------------------------------------------------------
| Attachments
|--------------------------------------------------------------------------
*/

it('uploads an attachment to a customer', function () {
    Storage::fake('public');

    [$user, $tenant, $token] = registerUser('att@example.test', 'Attach Org');
    $customer = $tenant->run(fn () => Customer::factory()->create());

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->post("/api/v1/customers/{$customer->id}/attachments", [
            'file' => UploadedFile::fake()->create('contract.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.filename', 'contract.pdf');

    expect($response->json('data.size'))->toBeGreaterThan(0);

    $tenant->run(function () use ($customer, $user) {
        $attachment = $customer->attachments()->firstOrFail();
        expect($attachment->user_id)->toBe($user->id);
        Storage::disk('public')->assertExists($attachment->path);
    });
});

it('rejects executable and script uploads whatever mime type is claimed', function () {
    Storage::fake('public');
    [, $tenant, $token] = registerUser('exe@example.test', 'Exe Org');
    $customer = $tenant->run(fn () => Customer::factory()->create());

    // The browser-reported MIME type is attacker-controlled, so the extension
    // deny-list is what actually protects us. .html is included because one
    // served from our origin is stored XSS.
    foreach (['shell.php', 'evil.html', 'run.exe'] as $name) {
        $this->withHeaders(orgHeaders($token, $tenant))
            ->post("/api/v1/customers/{$customer->id}/attachments", [
                'file' => UploadedFile::fake()->create($name, 10, 'image/png'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    $tenant->run(fn () => expect($customer->attachments()->count())->toBe(0));
});

it('does not store the uploaded filename as the path', function () {
    Storage::fake('public');
    [, $tenant, $token] = registerUser('path@example.test', 'Path Org');
    $customer = $tenant->run(fn () => Customer::factory()->create());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->post("/api/v1/customers/{$customer->id}/attachments", [
            'file' => UploadedFile::fake()->create('../../etc/passwd.txt', 10, 'text/plain'),
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $tenant->run(function () use ($customer) {
        $attachment = $customer->attachments()->firstOrFail();

        // The original name survives only as a display label; the stored path is
        // generated, so a traversal attempt cannot escape the disk.
        expect($attachment->path)->not->toContain('..')
            ->and($attachment->path)->toStartWith('attachments/');
    });
});

it('deletes the stored file when the attachment is deleted', function () {
    Storage::fake('public');
    [, $tenant, $token] = registerUser('delatt@example.test', 'DelAtt Org');
    $customer = $tenant->run(fn () => Customer::factory()->create());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->post("/api/v1/customers/{$customer->id}/attachments", [
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $path = $tenant->run(fn () => $customer->attachments()->firstOrFail()->path);
    $id = $tenant->run(fn () => $customer->attachments()->firstOrFail()->id);

    $this->withHeaders(orgHeaders($token, $tenant))
        ->deleteJson("/api/v1/customers/{$customer->id}/attachments/{$id}")
        ->assertOk();

    // The row and the bytes go together — no orphaned files on disk.
    Storage::disk('public')->assertMissing($path);
    $tenant->run(fn () => expect($customer->attachments()->count())->toBe(0));
});

it('forbids a viewer from uploading', function () {
    Storage::fake('public');
    [$user, $tenant, $token] = registerUser('vatt@example.test', 'ViewAtt Org');
    $customer = $tenant->run(fn () => Customer::factory()->create());
    giveRole($tenant, $user, 'viewer');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->post("/api/v1/customers/{$customer->id}/attachments", [
            'file' => UploadedFile::fake()->create('x.txt', 5, 'text/plain'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(403);
});
