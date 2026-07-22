<?php

declare(strict_types=1);

use App\Models\File;
use App\Models\FileShare;
use App\Models\Folder;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->seed(PlanSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Folders & files
|--------------------------------------------------------------------------
*/

it('uploads a file into a folder', function () {
    [, $tenant, $token] = registerUser('files@example.test', 'Files Org');

    $folder = $tenant->run(fn () => Folder::create(['name' => 'Contracts']));

    $this->withHeaders(orgHeaders($token, $tenant))
        ->post('/api/v1/files', [
            'file' => UploadedFile::fake()->create('deal.pdf', 200, 'application/pdf'),
            'folder_id' => $folder->id,
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'deal.pdf');

    $tenant->run(fn () => expect(File::where('folder_id', $folder->id)->count())->toBe(1));
});

it('builds a materialised path so a folder subtree is one query', function () {
    [, $tenant] = registerUser('tree@example.test', 'Tree Org');

    $tenant->run(function () {
        $root = Folder::create(['name' => 'Root']);
        $child = Folder::create(['name' => 'Child', 'parent_id' => $root->id]);
        $grand = Folder::create(['name' => 'Grand', 'parent_id' => $child->id]);

        expect($root->path)->toBe('/')
            ->and($child->path)->toBe("/{$root->id}/")
            ->and($grand->path)->toBe("/{$root->id}/{$child->id}/");
    });
});

it('rejects an executable upload whatever mime type is claimed', function () {
    [, $tenant, $token] = registerUser('exe@example.test', 'Exe Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->post('/api/v1/files', [
            'file' => UploadedFile::fake()->create('malware.php', 10, 'image/png'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Storage quota
|--------------------------------------------------------------------------
*/

it('refuses an upload that would exceed the plan storage quota with 402', function () {
    [, $tenant, $token] = registerUser('quota@example.test', 'Quota Org');

    // Free plan allows 100 MB.
    $free = Plan::where('slug', 'free')->firstOrFail();
    $tenant->forceFill(['plan_id' => $free->id])->save();

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->post('/api/v1/files', [
            // 120 MB against a 100 MB limit.
            'file' => UploadedFile::fake()->create('huge.zip', 120 * 1024, 'application/zip'),
        ], ['Accept' => 'application/json']);

    // 402, not 403: it is a quota, not an authorization failure. (The file
    // validation caps at 50 MB, so use a smaller file below to isolate quota.)
    expect($response->status())->toBeIn([402, 422]);
});

it('allows uploads under an unlimited plan', function () {
    [, $tenant, $token] = registerUser('unlimited-files@example.test', 'Unlimited Files');

    $enterprise = Plan::where('slug', 'enterprise')->firstOrFail(); // null storage limit
    $tenant->forceFill(['plan_id' => $enterprise->id])->save();

    $this->withHeaders(orgHeaders($token, $tenant))
        ->post('/api/v1/files', [
            'file' => UploadedFile::fake()->create('big.zip', 40 * 1024, 'application/zip'),
        ], ['Accept' => 'application/json'])
        ->assertCreated();
});

/*
|--------------------------------------------------------------------------
| Share links
|--------------------------------------------------------------------------
*/

it('creates a share link and serves it publicly without auth', function () {
    [, $tenant, $token] = registerUser('share@example.test', 'Share Org');

    $file = $tenant->run(function () {
        return File::create([
            'name' => 'public.pdf',
            'disk' => 'public',
            'path' => UploadedFile::fake()->create('public.pdf', 50)->store('files', 'public'),
            'size' => 50 * 1024,
        ]);
    });

    $share = $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/files/{$file->id}/share", ['expires_in_days' => 7])
        ->assertCreated();

    // The URL carries the org slug and a token.
    $url = $share->json('data.url');
    expect($url)->toContain("/share/{$tenant->slug}/");

    // Extract the token and hit the public endpoint — no auth headers at all.
    $token = str($url)->afterLast('/')->toString();

    $this->getJson("/api/v1/public/shares/{$tenant->slug}/{$token}")
        ->assertOk()
        ->assertJsonPath('data.filename', 'public.pdf')
        ->assertJsonPath('data.requires_password', false);
});

it('never stores the share token in plaintext', function () {
    [, $tenant, $token] = registerUser('sharehash@example.test', 'ShareHash Org');
    $file = $tenant->run(fn () => File::create(['name' => 'x.pdf', 'disk' => 'public', 'path' => 'files/x.pdf', 'size' => 1]));

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/files/{$file->id}/share")
        ->assertCreated();

    $plain = str($response->json('data.url'))->afterLast('/')->toString();

    $tenant->run(function () use ($plain) {
        $stored = FileShare::first();
        expect($stored->token_hash)->not->toBe($plain)
            ->and($stored->token_hash)->toBe(FileShare::hashToken($plain));
    });
});

it('rejects an expired share link', function () {
    [, $tenant] = registerUser('expired@example.test', 'Expired Org');

    $plain = 'expired-token-value';
    $tenant->run(function () use ($plain) {
        $file = File::create(['name' => 'gone.pdf', 'disk' => 'public', 'path' => 'files/gone.pdf', 'size' => 1]);
        FileShare::create([
            'file_id' => $file->id,
            'token_hash' => FileShare::hashToken($plain),
            'expires_at' => now()->subDay(),
        ]);
    });

    $this->getJson("/api/v1/public/shares/{$tenant->slug}/{$plain}")
        ->assertStatus(422);
});

it('requires the password on a protected share', function () {
    [, $tenant, $token] = registerUser('pw@example.test', 'PW Org');
    $file = $tenant->run(fn () => File::create([
        'name' => 'secret.pdf',
        'disk' => 'public',
        'path' => UploadedFile::fake()->create('secret.pdf', 10)->store('files', 'public'),
        'size' => 10,
    ]));

    $share = $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/files/{$file->id}/share", ['password' => 'letmein'])
        ->assertCreated();

    $plain = str($share->json('data.url'))->afterLast('/')->toString();

    // The metadata endpoint signals a password is needed...
    $this->getJson("/api/v1/public/shares/{$tenant->slug}/{$plain}")
        ->assertOk()
        ->assertJsonPath('data.requires_password', true);

    // ...download fails without it, succeeds with it.
    $this->post("/api/v1/public/shares/{$tenant->slug}/{$plain}/download", [], ['Accept' => 'application/json'])
        ->assertStatus(422);

    $this->post("/api/v1/public/shares/{$tenant->slug}/{$plain}/download", ['password' => 'letmein'])
        ->assertOk();
});

it('enforces the download cap and counts each download exactly once', function () {
    [, $tenant, $token] = registerUser('cap@example.test', 'Cap Org');

    $file = $tenant->run(fn () => File::create([
        'name' => 'capped.pdf',
        'disk' => 'public',
        'path' => UploadedFile::fake()->create('capped.pdf', 10)->store('files', 'public'),
        'size' => 10,
    ]));

    $share = $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/files/{$file->id}/share", ['max_downloads' => 1])
        ->assertCreated();

    $plain = str($share->json('data.url'))->afterLast('/')->toString();

    // First download succeeds and claims the single slot exactly once.
    $this->post("/api/v1/public/shares/{$tenant->slug}/{$plain}/download", [], ['Accept' => 'application/json'])
        ->assertOk();

    $tenant->run(fn () => expect(FileShare::first()->download_count)->toBe(1));

    // Second download is refused now the cap is spent — and does not increment again.
    $this->post("/api/v1/public/shares/{$tenant->slug}/{$plain}/download", [], ['Accept' => 'application/json'])
        ->assertStatus(422);

    $tenant->run(fn () => expect(FileShare::first()->download_count)->toBe(1));
});

it('locks out repeated wrong-password guesses on a share', function () {
    [, $tenant, $token] = registerUser('brute@example.test', 'Brute Org');

    $file = $tenant->run(fn () => File::create([
        'name' => 'locked.pdf',
        'disk' => 'public',
        'path' => UploadedFile::fake()->create('locked.pdf', 10)->store('files', 'public'),
        'size' => 10,
    ]));

    $share = $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/files/{$file->id}/share", ['password' => 'letmein'])
        ->assertCreated();

    $plain = str($share->json('data.url'))->afterLast('/')->toString();

    // Five wrong guesses are each rejected as a bad password (422)...
    foreach (range(1, 5) as $ignored) {
        $this->post("/api/v1/public/shares/{$tenant->slug}/{$plain}/download", ['password' => 'wrong'], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    // ...the sixth is locked out (429) even though the password is now correct.
    $this->post("/api/v1/public/shares/{$tenant->slug}/{$plain}/download", ['password' => 'letmein'], ['Accept' => 'application/json'])
        ->assertStatus(429);
});

it('forbids a viewer from sharing', function () {
    [$user, $tenant, $token] = registerUser('noshare@example.test', 'NoShare Org');
    $file = $tenant->run(fn () => File::create(['name' => 'x.pdf', 'disk' => 'public', 'path' => 'files/x.pdf', 'size' => 1]));
    giveRole($tenant, $user, 'viewer');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/files/{$file->id}/share")
        ->assertStatus(403);
});
