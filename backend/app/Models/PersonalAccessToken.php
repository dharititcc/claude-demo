<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * API tokens belong to central users, so they live in the central database.
 *
 * Pinning the connection matters for correctness, not tidiness. Sanctum resolves
 * the bearer token using this model on the *default* connection. In the normal
 * request flow the default is still central at that point, because `auth:sanctum`
 * runs before the tenancy middleware — but that ordering is not a guarantee:
 *
 *  - Under Octane the container is reused between requests, so a tenant
 *    connection left active by a previous request would still be the default
 *    when the next request authenticates.
 *  - Queued jobs and console commands can authenticate inside tenant context.
 *
 * In those cases an unpinned model would look for `personal_access_tokens` in a
 * tenant database — failing outright, or (worse) matching a different row if a
 * tenant database happened to contain that table.
 *
 * @property int|null $impersonator_id
 * @property string|null $impersonated_tenant_id
 * @property-read User|null $impersonator
 * @property-read Tenant|null $impersonatedTenant
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use UsesCentralConnection;

    /**
     * Sanctum's base fillable does not include the impersonation columns, and
     * they are set explicitly at token creation, so they are added here rather
     * than relying on forceFill everywhere.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'impersonator_id',
        'impersonated_tenant_id',
    ];

    /**
     * True when this token was minted for an impersonation session rather than a
     * normal login.
     */
    public function isImpersonation(): bool
    {
        return $this->impersonator_id !== null;
    }

    /**
     * The Super Admin acting behind an impersonation token.
     *
     * @return BelongsTo<User, $this>
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    /**
     * The single organization an impersonation token is confined to.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function impersonatedTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'impersonated_tenant_id');
    }
}
