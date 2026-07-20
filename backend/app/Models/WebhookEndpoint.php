<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An outbound webhook subscription.
 *
 * @property int $id
 * @property string $url
 * @property array<int, string> $events
 * @property string $secret
 * @property bool $is_active
 * @property int|null $created_by
 * @property int $consecutive_failures
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 */
class WebhookEndpoint extends Model
{
    use UsesTenantConnection;

    /** Consecutive failures after which the endpoint auto-pauses. */
    public const FAILURE_THRESHOLD = 10;

    /** @var list<string> */
    protected $fillable = ['url', 'events', 'secret', 'is_active', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['secret'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'consecutive_failures' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WebhookEndpoint $endpoint) {
            if (blank($endpoint->secret)) {
                $endpoint->secret = 'whsec_'.Str::random(40);
            }
        });
    }

    public function subscribesTo(string $event): bool
    {
        return in_array('*', $this->events, true) || in_array($event, $this->events, true);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * @param Builder<WebhookEndpoint> $query
     * @return Builder<WebhookEndpoint>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
