<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A block of tracked time against a task.
 *
 * A NULL `ended_at` means the timer is still running. `seconds` is written when
 * the timer stops rather than derived on read, so a stopped entry's duration is
 * fixed even if the row is later edited or the clock is adjusted.
 *
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property string|null $description
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int $seconds
 * @property bool $billable
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Task|null $task
 */
class TimeEntry extends Model
{
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = ['task_id', 'user_id', 'description', 'started_at', 'ended_at', 'seconds', 'billable'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'seconds' => 0,
        'billable' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'seconds' => 'integer',
            'billable' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function isRunning(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Elapsed seconds, including a timer that is still running.
     *
     * A running timer has `seconds = 0` in the database, so a UI that showed the
     * stored value would display 00:00 for an active timer.
     */
    public function elapsedSeconds(): int
    {
        // diffInSeconds returns a float in current Carbon, so cast explicitly —
        // a fractional second has no meaning here and the return type is int.
        return $this->isRunning()
            ? (int) max(0, now()->diffInSeconds($this->started_at, absolute: true))
            : $this->seconds;
    }

    /**
     * @param Builder<TimeEntry> $query
     * @return Builder<TimeEntry>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
