<?php

declare(strict_types=1);

use App\Models\User;

it('issues a token for valid credentials', function () {
    registerUser('login@example.test', 'Login Org');

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'login@example.test',
        'password' => 'Str0ng!Passw0rd#2026',
        'device_name' => 'pest-suite',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.email', 'login@example.test')
        ->assertJsonStructure(['data' => ['token', 'organizations']]);

    expect($response->json('data.organizations'))->toHaveCount(1);
});

it('records a successful login with a timestamp and ip', function () {
    registerUser('audit@example.test', 'Audit Org');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'audit@example.test',
        'password' => 'Str0ng!Passw0rd#2026',
    ])->assertOk();

    $this->assertDatabaseHas('login_histories', [
        'email' => 'audit@example.test',
        'successful' => true,
    ]);

    expect(User::where('email', 'audit@example.test')->first()->last_login_at)->not->toBeNull();
});

it('rejects an invalid password and records the failure', function () {
    registerUser('bad@example.test', 'Bad Org');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'bad@example.test',
        'password' => 'TotallyWrong#1234',
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    $this->assertDatabaseHas('login_histories', [
        'email' => 'bad@example.test',
        'successful' => false,
        'reason' => 'invalid_credentials',
    ]);
});

it('does not reveal whether an email address exists', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'ghost@example.test',
        'password' => 'TotallyWrong#1234',
    ])->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
});

it('locks the account out with 429 after repeated failures', function () {
    registerUser('lock@example.test', 'Lock Org');

    // Five failures are permitted...
    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'lock@example.test',
            'password' => 'TotallyWrong#1234',
        ])->assertStatus(422);
    }

    // ...the sixth is throttled, and must be distinguishable from a plain 422.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'lock@example.test',
        'password' => 'TotallyWrong#1234',
    ])->assertStatus(429);

    // Even the correct password is refused while locked out.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'lock@example.test',
        'password' => 'Str0ng!Passw0rd#2026',
    ])->assertStatus(429);

    $this->assertDatabaseHas('login_histories', [
        'email' => 'lock@example.test',
        'reason' => 'locked_out',
    ]);
});

it('refuses to authenticate a suspended account', function () {
    [$user] = registerUser('suspended@example.test', 'Suspended Org');
    $user->update(['status' => 'suspended']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'suspended@example.test',
        'password' => 'Str0ng!Passw0rd#2026',
    ])->assertStatus(422);

    $this->assertDatabaseHas('login_histories', [
        'email' => 'suspended@example.test',
        'reason' => 'inactive_account',
    ]);
});

it('revokes only the current token on logout', function () {
    [$user, , $token] = registerUser('logout@example.test', 'Logout Org');
    $user->createToken('other-device');

    expect($user->tokens()->count())->toBe(2);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    expect($user->fresh()->tokens()->count())->toBe(1)
        ->and($user->fresh()->tokens()->first()->name)->toBe('other-device');
});

it('rejects requests without a token', function () {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});
