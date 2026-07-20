<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A record of one webhook delivery attempt, kept for debugging and replay.
 *
 * @property int $id
 * @property int $webhook_endpoint_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property int|null $status_code
 * @property bool $success
 * @property string|null $error
 * @property int $attempt
 * @property Carbon|null $delivered_at
 */
class WebhookDelivery extends Model
{
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = [
        'webhook_endpoint_id', 'event', 'payload',
        'status_code', 'success', 'error', 'attempt', 'delivered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'success' => 'boolean',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
