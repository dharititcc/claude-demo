<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Grant a user platform-wide super-admin access, creating the account if it
 * does not exist yet.
 *
 * A super admin is a *platform* identity, not an organization member: the flag
 * grants a `Gate::before` bypass (AppServiceProvider), entry into any tenant
 * without membership (InitializeTenancyForUser), and exemption from plan limits
 * (EnforcePlanLimit). Because that reaches every organization's data, this is
 * deliberately a command with a confirmation step rather than something buried
 * in a seeder — promoting a super admin should be an explicit, auditable act.
 */
class MakeSuperAdmin extends Command
{
    protected $signature = 'app:make-super-admin
        {email : The account to create or promote}
        {--name= : Display name (new accounts only; defaults to the email local-part)}
        {--password= : Set an explicit password; omit to auto-generate one for a new account}
        {--force : Skip the confirmation prompt (required in production)}';

    protected $description = 'Create or promote a user to platform super admin';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Not a valid email address: {$email}");

            return self::INVALID;
        }

        $existing = User::where('email', $email)->first();

        // Promoting in production, or promoting an already-real account, both
        // deserve an explicit yes — a mis-typed email here hands someone every
        // organization's data.
        if (! $this->option('force')) {
            if (app()->environment('production')) {
                $this->error('Refusing to run unattended in production. Re-run with --force if you mean it.');

                return self::FAILURE;
            }

            $verb = $existing ? "promote the EXISTING account {$email}" : "create a NEW super admin {$email}";

            if (! $this->confirm("This grants platform-wide access to every organization's data. Really {$verb}?")) {
                $this->comment('Aborted. Nothing changed.');

                return self::SUCCESS;
            }
        }

        return $existing
            ? $this->promote($existing)
            : $this->create($email);
    }

    /**
     * Flip the flag on an existing user, leaving their password and profile
     * untouched — they already have a way in.
     */
    private function promote(User $user): int
    {
        if ($user->is_super_admin) {
            $this->info("{$user->email} is already a super admin. Nothing to do.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_super_admin' => true])->save();

        $this->info("Promoted {$user->email} to super admin.");
        $this->line('Their existing password is unchanged.');

        return self::SUCCESS;
    }

    /**
     * Create a fresh, platform-only super admin — verified and active so it can
     * sign in immediately, and belonging to no organization by design.
     */
    private function create(string $email): int
    {
        $password = (string) ($this->option('password') ?? Str::password(16));

        try {
            $this->validatePassword($password);
        } catch (ValidationException $e) {
            foreach ($e->errors()['password'] ?? [] as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        $user = new User;
        $user->forceFill([
            'name' => (string) ($this->option('name') ?? Str::of($email)->before('@')->headline()),
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'status' => 'active',
            'is_super_admin' => true,
        ])->save();

        $this->info("Created super admin {$user->email}.");

        // A generated password is shown once, here, and never stored in plain
        // text — the same contract as the app's recovery codes.
        if ($this->option('password') === null) {
            $this->newLine();
            $this->warn('Generated password (shown once — save it now):');
            $this->line("  {$password}");
            $this->newLine();
            $this->comment('Change it after first sign-in via PUT /api/v1/auth/password.');
        }

        return self::SUCCESS;
    }

    /**
     * Hold new super-admin passwords to the same policy as every other account
     * (AppServiceProvider): 12+ chars, mixed case, a number, and a symbol.
     *
     * @throws ValidationException
     */
    private function validatePassword(string $password): void
    {
        validator(
            ['password' => $password],
            ['password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()]],
        )->validate();
    }
}
