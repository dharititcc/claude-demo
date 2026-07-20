<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * An outstanding invitation to join an organization.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $email
 * @property string $role
 * @property string $token_hash
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property-read Tenant $tenant
 * @property-read User|null $inviter
 */
class Invitation extends Model
{
    // Notified directly rather than via a User: the invitee may have no account
    // yet, so there is no User to route the mail through.
    use Notifiable;
    use UsesCentralConnection;

    /**
     * Route notifications to the invited address.
     */
    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Hash a plaintext token for storage/lookup.
     *
     * SHA-256 rather than bcrypt: the token is 40+ bytes of CSPRNG output, so it
     * has no guessable structure to brute-force, and lookup must be a single
     * indexed query rather than a scan comparing every row.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function roleEnum(): Role
    {
        return Role::from($this->role);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /**
     * @param Builder<Invitation> $query
     * @return Builder<Invitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }
}
