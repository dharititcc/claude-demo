<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoginHistory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Authentication use-cases: registration, credential verification, login
 * throttling, and audit trail. Controllers stay thin and delegate here.
 */
class AuthService
{
    /** Failed attempts allowed per email+IP before lockout. */
    private const MAX_ATTEMPTS = 5;

    /** Lockout duration in seconds. */
    private const DECAY_SECONDS = 900; // 15 minutes

    /** How long a half-authenticated 2FA challenge stays valid. */
    private const CHALLENGE_TTL = 300; // 5 minutes

    /** Wrong codes allowed against one challenge before it is torn up. */
    private const MAX_CHALLENGE_ATTEMPTS = 5;

    public function __construct(private readonly OrganizationService $organizations) {}

    /**
     * Register a user and their first organization as one atomic unit.
     *
     * @param array<string, mixed> $data
     * @return array{user: User, tenant: Tenant}
     */
    public function register(array $data): array
    {
        // The tenant database is provisioned by an event listener outside this
        // transaction (DDL cannot be rolled back), so the central rows are
        // committed first and the org is created after.
        $user = DB::transaction(fn () => User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'timezone' => $data['timezone'] ?? 'UTC',
        ]));

        $tenant = $this->organizations->create($user, [
            'name' => $data['organization_name'],
        ]);

        event(new Registered($user));

        return ['user' => $user, 'tenant' => $tenant];
    }

    /**
     * Verify credentials and return the user, recording the attempt either way.
     *
     * @throws ValidationException
     */
    public function attempt(Request $request, string $email, string $password): User
    {
        $key = $this->throttleKey($email, $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->record($request, $email, null, false, 'locked_out');

            throw ValidationException::withMessages([
                'email' => __('Too many login attempts. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ])->status(429);
        }

        $user = User::where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $this->record($request, $email, $user?->id, false, 'invalid_credentials');

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        if ($user->status !== 'active') {
            $this->record($request, $email, $user->id, false, 'inactive_account');

            throw ValidationException::withMessages([
                'email' => __('Your account is not active.'),
            ]);
        }

        RateLimiter::clear($key);
        $this->record($request, $email, $user->id, true);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return $user;
    }

    /**
     * Issue a Sanctum token. The device name lets users audit and revoke
     * individual sessions.
     */
    public function issueToken(User $user, string $deviceName): string
    {
        return $user->createToken($deviceName)->plainTextToken;
    }

    /**
     * Issue a short-lived, single-use handle representing a half-authenticated
     * session. The caller must exchange it plus a valid TOTP code for a real
     * API token, so credentials alone never grant access to a 2FA account.
     */
    public function issueTwoFactorChallenge(User $user): string
    {
        $challenge = Str::random(64);

        Cache::put($this->challengeKey($challenge), $user->id, self::CHALLENGE_TTL);

        return $challenge;
    }

    /**
     * Resolve a challenge handle back to its user *without* spending it.
     *
     * Deliberately not consume-on-read: a single mistyped digit would then throw
     * the user back to the password prompt. The handle survives a wrong code and
     * is spent by completeTwoFactorChallenge() on success, or destroyed by
     * recordFailedTwoFactorAttempt() once the attempts run out.
     *
     * @throws ValidationException
     */
    public function resolveTwoFactorChallenge(string $challenge): User
    {
        $userId = Cache::get($this->challengeKey($challenge));

        if ($userId === null) {
            throw ValidationException::withMessages([
                'challenge_token' => __('This login challenge is invalid or has expired.'),
            ]);
        }

        return User::findOrFail($userId);
    }

    /**
     * Spend a challenge once its code has been accepted, so the same handle
     * cannot be exchanged for a second token.
     */
    public function completeTwoFactorChallenge(string $challenge): void
    {
        Cache::forget($this->challengeKey($challenge));
        Cache::forget($this->challengeAttemptKey($challenge));
    }

    /**
     * Count a wrong code against this challenge and destroy the challenge once
     * the budget is gone.
     *
     * Six digits is a million combinations, but only the ~90s of codes accepted
     * around now matter, so an unbounded challenge is genuinely guessable. The
     * ceiling is per challenge rather than per IP: a distributed guesser would
     * otherwise get a fresh budget from every address it holds, while all of
     * them hammer the same handle.
     */
    public function recordFailedTwoFactorAttempt(string $challenge): void
    {
        $key = $this->challengeAttemptKey($challenge);
        $attempts = ((int) Cache::get($key, 0)) + 1;

        if ($attempts >= self::MAX_CHALLENGE_ATTEMPTS) {
            $this->completeTwoFactorChallenge($challenge);

            return;
        }

        Cache::put($key, $attempts, self::CHALLENGE_TTL);
    }

    private function challengeKey(string $challenge): string
    {
        return '2fa-challenge:'.hash('sha256', $challenge);
    }

    private function challengeAttemptKey(string $challenge): string
    {
        return '2fa-challenge-attempts:'.hash('sha256', $challenge);
    }

    private function record(Request $request, string $email, ?int $userId, bool $successful, ?string $reason = null): void
    {
        LoginHistory::create([
            'user_id' => $userId,
            'email' => $email,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'successful' => $successful,
            'reason' => $reason,
            'attempted_at' => now(),
        ]);
    }

    private function throttleKey(string $email, ?string $ip): string
    {
        return 'login:'.mb_strtolower($email).'|'.$ip;
    }
}
