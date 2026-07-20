<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Central user identity. A user authenticates once and can belong to many
 * organizations (tenants); their role is resolved per-organization from the
 * active tenant database (see HasRoles + UsesCentralConnection docs).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $avatar
 * @property string|null $phone
 * @property string $locale
 * @property string $timezone
 * @property string $status
 * @property bool $is_super_admin
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_used_window
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Tenant> $organizations
 * @property-read Collection<int, Role> $roles
 * @property-read OrganizationUser|null $pivot Present when loaded via Tenant::members()
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use UsesCentralConnection;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'phone',
        'locale',
        'timezone',
        'status',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Mirror the database defaults so a freshly created model reports the same
     * state as the persisted row (without an extra refresh query).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locale' => 'en',
        'timezone' => 'UTC',
        'status' => 'active',
        'is_super_admin' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            // Encrypted, not hashed: both must be readable again. The secret has
            // to be re-derived on every TOTP check, and recovery codes are shown
            // to the user after they are issued. Encryption means APP_KEY is what
            // stands between a database dump and every second factor on the
            // platform — see the key-handling section of DEPLOYMENT.md.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Organizations (tenants) this user belongs to.
     *
     * @return BelongsToMany<Tenant, $this, OrganizationUser, 'pivot'>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'organization_user', 'user_id', 'tenant_id')
            ->using(OrganizationUser::class)
            ->withPivot('is_owner')
            ->withTimestamps();
    }

    /**
     * @return HasMany<LoginHistory, $this>
     */
    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    /**
     * In-app notifications, overridden to use the tenant-pinned model.
     *
     * The default Notifiable relation uses Laravel's DatabaseNotification, which
     * would inherit this user's central connection — but the notifications table
     * lives in the tenant database. Every notification method routes through
     * notifications()/newBaseQueryBuilder, so overriding this is enough.
     *
     * @return MorphMany<DatabaseNotification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')->latest();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function belongsToOrganization(string $tenantId): bool
    {
        return $this->organizations()->whereKey($tenantId)->exists();
    }
}
