<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Password reset, end to end.
 *
 * This flow shipped untested, and two halves of it were broken: the reset mail
 * could not build its link at all (it defaulted to route('password.reset'), a
 * web route this headless API does not define), and the SPA had no page to
 * redeem the token on. The first case is what `it builds a link into the SPA`
 * pins shut.
 */
function resetPasswordUser(string $email = 'reset-me@example.test'): User
{
    [$user] = registerUser($email, 'Reset Co');

    return $user;
}

it('accepts a reset request and sends the notification', function () {
    Notification::fake();

    $user = resetPasswordUser();

    test()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
        ->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('builds a link into the SPA rather than blowing up on a missing web route', function () {
    Notification::fake();

    $user = resetPasswordUser();

    test()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        // Rendering the mail is what used to throw RouteNotFoundException.
        $url = $notification->toMail($user)->actionUrl;

        expect($url)
            ->toStartWith(rtrim((string) config('app.frontend_url'), '/').'/reset-password')
            ->toContain('token='.$notification->token)
            // The reset endpoint needs the address too, so the link carries it.
            ->toContain('email='.urlencode($user->email));

        return true;
    });
});

it('answers the same way for an unregistered address, so users cannot be enumerated', function () {
    Notification::fake();

    $known = resetPasswordUser();

    $forKnown = test()->postJson('/api/v1/auth/forgot-password', ['email' => $known->email])->assertOk();
    $forUnknown = test()->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])->assertOk();

    expect($forUnknown->json('message'))->toBe($forKnown->json('message'));

    // Identical wording, and only the real address actually produced mail.
    Notification::assertSentTimes(ResetPassword::class, 1);
});

it('resets the password with a valid token', function () {
    $user = resetPasswordUser();
    $token = Password::createToken($user);

    test()->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'N3w-Passw0rd!x',
        'password_confirmation' => 'N3w-Passw0rd!x',
    ])->assertOk();

    expect(Hash::check('N3w-Passw0rd!x', $user->fresh()->password))->toBeTrue();
});

it('revokes every existing api token, because a reset implies compromise', function () {
    $user = resetPasswordUser();
    $user->createToken('phone');
    $user->createToken('laptop');

    expect($user->tokens()->count())->toBeGreaterThan(0);

    test()->postJson('/api/v1/auth/reset-password', [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'N3w-Passw0rd!x',
        'password_confirmation' => 'N3w-Passw0rd!x',
    ])->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('refuses a token that belongs to a different address', function () {
    $user = resetPasswordUser();
    $other = resetPasswordUser('someone-else@example.test');

    test()->postJson('/api/v1/auth/reset-password', [
        'token' => Password::createToken($other),
        'email' => $user->email,
        'password' => 'N3w-Passw0rd!x',
        'password_confirmation' => 'N3w-Passw0rd!x',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('refuses a garbage token', function () {
    $user = resetPasswordUser();

    test()->postJson('/api/v1/auth/reset-password', [
        'token' => 'not-a-real-token',
        'email' => $user->email,
        'password' => 'N3w-Passw0rd!x',
        'password_confirmation' => 'N3w-Passw0rd!x',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('refuses to spend the same token twice', function () {
    $user = resetPasswordUser();
    $token = Password::createToken($user);

    $payload = [
        'token' => $token,
        'email' => $user->email,
        'password' => 'N3w-Passw0rd!x',
        'password_confirmation' => 'N3w-Passw0rd!x',
    ];

    test()->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
    test()->postJson('/api/v1/auth/reset-password', $payload)->assertStatus(422);
});

it('enforces the password policy on the new password', function () {
    $user = resetPasswordUser();

    test()->postJson('/api/v1/auth/reset-password', [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('requires the confirmation to match', function () {
    $user = resetPasswordUser();

    test()->postJson('/api/v1/auth/reset-password', [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'N3w-Passw0rd!x',
        'password_confirmation' => 'D1fferent-Passw0rd!',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});
