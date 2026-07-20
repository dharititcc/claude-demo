<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication (RFC 6238).
 *
 * Two properties drive the design here:
 *
 * 1. **Enable is two steps, not one.** `enable()` stores a secret but leaves
 *    `two_factor_confirmed_at` null, and the account is not actually protected
 *    until `confirm()` succeeds with a code from the authenticator. A one-step
 *    enable locks a user out of their own account whenever the QR scan silently
 *    fails or the device clock is skewed — they would only discover it at the
 *    next login, with no way back in. Until confirmation, login is unaffected.
 *
 * 2. **A code is accepted at most once.** RFC 6238 §5.2 requires it: codes stay
 *    valid for a whole time step, so anyone who observes one can replay it
 *    inside that window. We record the time step each success came from and
 *    refuse anything not strictly newer.
 */
class TwoFactorService
{
    /** How many recovery codes to issue. */
    private const RECOVERY_CODE_COUNT = 8;

    /**
     * Time steps of clock drift tolerated either side of now.
     *
     * One step (30s) each way. Every extra step widens the window an attacker
     * may guess in, so this stays as small as real-world clock skew allows.
     */
    private const WINDOW = 1;

    /**
     * The "no code has ever been accepted" marker.
     *
     * Zero rather than null, and this is not cosmetic. google2fa's findValidOTP
     * returns `true` instead of the matched time step when it is handed a null
     * $oldTimestamp — so passing null stores `true`, MySQL writes it as 1, and
     * every later comparison runs against step 1 rather than the real one
     * (~58,000,000). The guard silently degrades to accepting every replay.
     * Any real timestamp is greater than zero, so this keeps the return an int.
     */
    private const NEVER_USED = 0;

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Begin enrolment: attach an unconfirmed secret and return what the user
     * needs to add the account to their authenticator.
     *
     * Regenerating on every call is deliberate — a user who abandons enrolment
     * halfway and restarts must not be able to confirm with a stale QR code.
     *
     * @return array{secret: string, otpauth_uri: string}
     */
    public function enable(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_window' => null,
        ])->save();

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->google2fa->getQRCodeUrl(
                (string) config('app.name'),
                $user->email,
                $secret,
            ),
        ];
    }

    /**
     * Complete enrolment by proving the authenticator works.
     *
     * Recovery codes are only issued here, at the moment 2FA actually starts
     * protecting the account — issuing them at enable() would hand out fallback
     * credentials for a factor that may never be switched on.
     *
     * @return list<string>|null Recovery codes, or null when the code is wrong.
     */
    public function confirm(User $user, string $code): ?array
    {
        if ($user->two_factor_secret === null) {
            return null;
        }

        // Confirmation verifies against the pending secret, so it cannot use
        // verify(): that path requires 2FA to already be enabled.
        $window = $this->google2fa->verifyKeyNewer(
            $user->two_factor_secret,
            $this->normalize($code),
            self::NEVER_USED,
            self::WINDOW,
        );

        // is_int, not !== false: the library returns a bare `true` on some
        // paths, and storing that would poison the replay guard.
        if (! is_int($window)) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
            'two_factor_last_used_window' => $window,
        ])->save();

        return $codes;
    }

    /**
     * Verify a TOTP code from a fully enrolled user, consuming its time step.
     */
    public function verify(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled() || $user->two_factor_secret === null) {
            return false;
        }

        $window = $this->google2fa->verifyKeyNewer(
            $user->two_factor_secret,
            $this->normalize($code),
            $user->two_factor_last_used_window ?? self::NEVER_USED,
            self::WINDOW,
        );

        // is_int, not !== false: see NEVER_USED. A `true` here would be stored
        // as 1 and every subsequent replay would sail through.
        if (! is_int($window)) {
            return false;
        }

        // Burn the time step before returning: a second presentation of this
        // same code is no longer "newer" and will be rejected.
        $user->forceFill(['two_factor_last_used_window' => $window])->save();

        return true;
    }

    /**
     * Spend a recovery code. Each works once and is destroyed on use.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $candidate = trim($code);

        foreach ($codes as $index => $stored) {
            // hash_equals, not ===: a recovery code is a credential, and string
            // comparison leaks its prefix through timing.
            if (hash_equals($stored, $candidate)) {
                unset($codes[$index]);

                $user->forceFill([
                    'two_factor_recovery_codes' => array_values($codes),
                ])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * Issue a fresh set, invalidating every previous code.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    /**
     * Turn 2FA off and destroy every artefact of it.
     *
     * Clearing the secret alone would leave recovery codes behind that still
     * work if 2FA is ever re-enabled with a new secret.
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_window' => null,
        ])->save();
    }

    /**
     * Confirm a password before a security-sensitive 2FA change.
     *
     * Someone with a hijacked token must not be able to disable the second
     * factor or roll the recovery codes without knowing the password.
     */
    public function confirmPassword(User $user, string $password): bool
    {
        return Hash::check($password, $user->password);
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn (): string => Str::upper(Str::random(5).'-'.Str::random(5)))
            ->values()
            ->all();
    }

    /**
     * Authenticator apps display codes in a "123 456" style and users paste
     * what they see, spaces and all.
     */
    private function normalize(string $code): string
    {
        return preg_replace('/\s+/', '', $code) ?? $code;
    }
}
