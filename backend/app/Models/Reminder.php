<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A nudge before an event. `sent_at` is set once fired so the scheduler cannot
 * send it twice.
 *
 * @property int $id
 * @property int $event_id
 * @property int $minutes_before
 * @property string $channel
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Reminder extends Model
{
    use UsesTenantConnection;

    public const CHANNELS = ['database', 'email'];

    /** @var list<string> */
    protected $fillable = ['event_id', 'minutes_before', 'channel', 'sent_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['minutes_before' => 15, 'channel' => 'database'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'minutes_before' => 'integer'];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @param Builder<Reminder> $query
     * @return Builder<Reminder>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('sent_at');
    }
}
