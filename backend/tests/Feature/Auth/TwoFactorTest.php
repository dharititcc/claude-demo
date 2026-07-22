<?php

declare(strict_types=1);

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

const TWO_FACTOR_PASSWORD = 'Str0ng!Passw0rd#2026';

/**
 * Authenticate the following request as this bearer token.
 *
 * The guard caches the first user it resolves within a test, so it has to be
 * dropped between requests that act as different callers.
 */
function as2fa(string $token): TestCase
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => "Bearer {$token}"]);
}

function currentOtp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

/**
 * Take a user all the way through enrolment.
 *
 * @return array{0: User, 1: string, 2: string, 3: list<string>} [user, token, secret, recoveryCodes]
 */
function enrolTwoFactor(string $email = 'tfa@example.test'): array
{
    [$user, , $token] = registerUser($email, 'TFA Org');

    $secret = as2fa($token)->postJson('/api/v1/auth/2fa/enable')
        ->assertOk()
        ->json('data.secret');

    $codes = as2fa($token)->postJson('/api/v1/auth/2fa/confirm', ['code' => currentOtp($secret)])
        ->assertOk()
        ->json('data.recovery_codes');

    return [$user->refresh(), $token, $secret, $codes];
}

/**
 * Pretend the clock has moved past the time step confirmation just spent.
 *
 * Confirming burns its own step (a code is accepted at most once), so a login
 * in the same 30 seconds would legitimately be refused. Real users hit this only
 * if they sign out and back in within half a minute of enrolling; tests would
 * hit it constantly, so wind the marker back instead of sleeping.
 */
function forgetSpentStep(User $user): void
{
    $user->forceFill(['two_factor_last_used_window' => null])->save();
}

it('does not protect the account until the code is confirmed', function () {
    [, , $token] = registerUser('pending@example.test', 'Pending Org');

    as2fa($token)->postJson('/api/v1/auth/2fa/enable')
        ->assertOk()
        ->assertJsonStructure(['data' => ['secret', 'otpauth_uri']]);

    // A secret exists, but enrolment was never finished — login must still work
    // normally, or a failed QR scan would lock the user out of their own account.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'pending@example.test',
        'password' => TWO_FACTOR_PASSWORD,
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);
});

it('reports enrolment as pending until confirmed', function () {
    [, , $token] = registerUser('status@example.test', 'Status Org');

    as2fa($token)->getJson('/api/v1/auth/2fa')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.pending_confirmation', false);

    as2fa($token)->postJson('/api/v1/auth/2fa/enable')->assertOk();

    as2fa($token)->getJson('/api/v1/auth/2fa')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.pending_confirmation', true);
});

it('enables two factor and issues recovery codes on confirmation', function () {
    [$user, $token, , $codes] = enrolTwoFactor();

    expect($codes)->toHaveCount(8)
        ->and($user->hasTwoFactorEnabled())->toBeTrue();

    as2fa($token)->getJson('/api/v1/auth/2fa')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.recovery_codes_remaining', 8);
});

it('rejects a wrong confirmation code and leaves the account unprotected', function () {
    [$user, , $token] = registerUser('wrongconfirm@example.test', 'Wrong Org');

    as2fa($token)->postJson('/api/v1/auth/2fa/enable')->assertOk();

    as2fa($token)->postJson('/api/v1/auth/2fa/confirm', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('refuses to re-enrol while two factor is already enabled', function () {
    [, $token] = enrolTwoFactor();

    // Silently re-issuing would swap the secret out from under a working
    // authenticator while the account stays protected by the old one.
    as2fa($token)->postJson('/api/v1/auth/2fa/enable')->assertStatus(409);
});

it('challenges instead of issuing a token when two factor is on', function () {
    enrolTwoFactor('challenge@example.test');

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'challenge@example.test',
        'password' => TWO_FACTOR_PASSWORD,
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.two_factor_required', true)
        ->assertJsonStructure(['data' => ['challenge_token']]);

    // The password alone must not yield anything that can call the API.
    expect($response->json('data.token'))->toBeNull();
});

it('exchanges a valid code for a token', function () {
    [$user, , $secret] = enrolTwoFactor('exchange@example.test');
    forgetSpentStep($user);

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'exchange@example.test',
        'password' => TWO_FACTOR_PASSWORD,
    ])->json('data.challenge_token');

    $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'code' => currentOtp($secret),
        'device_name' => 'pest-suite',
    ])->assertOk()->assertJsonStructure(['data' => ['token', 'user', 'organizations']]);
});

it('refuses to accept the same code twice', function () {
    [$user, , $secret] = enrolTwoFactor('replay@example.test');
    forgetSpentStep($user);

    $code = currentOtp($secret);

    $first = $this->postJson('/api/v1/auth/login', [
        'email' => 'replay@example.test',
        'password' => TWO_FACTOR_PASSWORD,
    ])->json('data.challenge_token');

    $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $first,
        'code' => $code,
    ])->assertOk();

    // A TOTP stays valid for its whole 30s step, so anyone who observed that
    // code could otherwise sign in again with it. RFC 6238 §5.2: accept once.
    $second = $this->postJson('/api/v1/auth/login', [
        'email' => 'replay@example.test',
        'password' => TWO_FACTOR_PASSWORD,
    ])->json('data.challenge_token');

    $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $second,
        'code' => $code,
    ])->assertStatus(422)->assertJsonValidationErrors('code');
});

it('survives a wrong code so a typo does not restart the sign-in', function () {
    [$user, , $secret] = enrolTwoFactor('typo@example.test');
    forgetSpentStep($user);

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'typo@example.test',
        'password' => TWO_FACTOR_PASSWORD,
    ])->json('data.challenge_token');

    $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'code' => '000000',
    ])->assertStatus(422);

    // Same challenge, right code: still good.
    $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'code' => currentOtp($secret),
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);
});

it('destroys the challenge after five wrong codes', function () {
    [$user, , $secret] = enrolTwoFactor('brute@example.test');
    forgetSpentStep($user);

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'brute@example.test',
        'password' => TWO_FACTOR_PASSWORD,
    ])->json('data.challenge_token');

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challenge,
            'code' => '000000',
        ])->assertStatus(422);
    }

    // Six digits is only a million combinations and just the codes around now
    // matter, so an unbounded challenge is genuinely guessable. Even the correct
    // code must not revive a spent challenge.
    $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'code' => currentOtp($secret),
    ])->assertStatus(422)->assertJsonValidationErrors('challenge_token');
});

it('accepts a recovery code and consumes it', function () {
    [, , , $codes] = enrolTwoFactor('recovery@example.test');

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'recovery@example.test',
        'password' => TWO_FACTOR_PASSWORD,
    ])->json('data.challenge_token');

    $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'recovery_code' => $codes[0],
    ])->assertOk()
        ->assertJsonStructure(['data' => ['token']])
        // Someone signing in on recovery codes has lost their authenticator and
        // needs to know the list is finite.
        ->assertJsonPath('data.recovery_codes_remaining', 7);
});

it('refuses a recovery code that has already been spent', function () {
    [, , , $codes] = enrolTwoFactor('spent@example.test');

    $useRecoveryCode = function (string $code) {
        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => 'spent@example.test',
            'password' => TWO_FACTOR_PASSWORD,
        ])->json('data.challenge_token');

        return $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challenge,
            'recovery_code' => $code,
        ]);
    };

    $useRecoveryCode($codes[0])->assertOk();
    $useRecoveryCode($codes[0])->assertStatus(422);
});

it('requires the password to disable two factor', function () {
    [$user, $token] = enrolTwoFactor('disable@example.test');

    // A stolen bearer token must not be enough to strip the factor that exists
    // to survive a stolen password.
    as2fa($token)->deleteJson('/api/v1/auth/2fa', ['password' => 'WrongPassword#123'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('clears every artefact when two factor is disabled', function () {
    [$user, $token] = enrolTwoFactor('clear@example.test');

    as2fa($token)->deleteJson('/api/v1/auth/2fa', ['password' => TWO_FACTOR_PASSWORD])->assertOk();

    $user->refresh();

    // Leaving recovery codes behind would leave working credentials for a
    // factor the user believes is gone.
    expect($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->two_factor_last_used_window)->toBeNull();

    // And login goes back to issuing a token outright.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'clear@example.test',
        'password' => TWO_FACTOR_PASSWORD,
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);
});

it('requires the password to regenerate recovery codes and kills the old ones', function () {
    [, $token, , $codes] = enrolTwoFactor('regen@example.test');

    as2fa($token)->postJson('/api/v1/auth/2fa/recovery-codes', ['password' => 'WrongPassword#123'])
        ->assertStatus(422);

    $fresh = as2fa($token)->postJson('/api/v1/auth/2fa/recovery-codes', ['password' => TWO_FACTOR_PASSWORD])
        ->assertOk()
        ->json('data.recovery_codes');

    expect($fresh)->toHaveCount(8)
        ->and(array_intersect($fresh, $codes))->toBeEmpty();

    // The point of regenerating is that a leaked list stops working.
    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'regen@example.test',
        'password' => TWO_FACTOR_PASSWORD,
    ])->json('data.challenge_token');

    $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'recovery_code' => $codes[0],
    ])->assertStatus(422);
});

it('stores the secret and recovery codes encrypted, not in the clear', function () {
    [$user, , $secret, $codes] = enrolTwoFactor('crypt@example.test');

    $row = DB::connection(config('tenancy.database.central_connection'))
        ->table('users')
        ->where('id', $user->id)
        ->first();

    // A database dump alone must not hand over everyone's second factor.
    expect($row->two_factor_secret)->not->toContain($secret)
        ->and($row->two_factor_recovery_codes)->not->toContain($codes[0])
        // ...but the application still reads them back.
        ->and($user->two_factor_secret)->toBe($secret);
});

it('never exposes the secret or recovery codes through the user resource', function () {
    [, $token] = enrolTwoFactor('leak@example.test');

    $me = as2fa($token)->getJson('/api/v1/auth/me')->assertOk();

    expect($me->json())->not->toHaveKey('data.two_factor_secret')
        ->and($me->json())->not->toHaveKey('data.two_factor_recovery_codes');
});

it('does not record a successful login until the 2FA challenge is completed', function () {
    [$user, , $secret] = enrolTwoFactor('audit-2fa@example.test');
    forgetSpentStep($user);

    // Password stage: the password is correct but the second factor is still
    // owed, so this is NOT a successful login and must not stamp last_login_*.
    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'audit-2fa@example.test',
        'password' => TWO_FACTOR_PASSWORD,
    ])->assertStatus(202)->json('data.challenge_token');

    $pending = LoginHistory::where('user_id', $user->id)->latest('id')->first();
    expect($pending->successful)->toBeFalse()
        ->and($pending->reason)->toBe('password_ok_2fa_pending')
        ->and($user->refresh()->last_login_at)->toBeNull();

    // Completing the challenge is the genuine login: a successful row is written
    // and last_login_* is stamped only now.
    $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'code' => currentOtp($secret),
    ])->assertOk();

    expect(LoginHistory::where('user_id', $user->id)->where('successful', true)->exists())->toBeTrue()
        ->and($user->refresh()->last_login_at)->not->toBeNull();
});

it('records a failed second-factor code in the login history', function () {
    [$user, , $secret] = enrolTwoFactor('audit-2fa-fail@example.test');
    forgetSpentStep($user);

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'audit-2fa-fail@example.test',
        'password' => TWO_FACTOR_PASSWORD,
    ])->json('data.challenge_token');

    $this->postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'code' => '000000',
    ])->assertStatus(422);

    $failed = LoginHistory::where('user_id', $user->id)->where('reason', 'invalid_2fa_code')->first();
    expect($failed)->not->toBeNull()->and($failed->successful)->toBeFalse();
});
