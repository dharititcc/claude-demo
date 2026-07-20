<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A calendar event or the master of a recurring series.
 *
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string|null $location
 * @property string $type
 * @property string $color
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property bool $all_day
 * @property string|null $recurrence_frequency
 * @property int $recurrence_interval
 * @property array<int, string>|null $recurrence_by_day
 * @property Carbon|null $recurrence_until
 * @property int|null $recurrence_count
 * @property int|null $parent_id
 * @property Carbon|null $original_starts_at
 * @property bool $is_cancelled
 * @property int|null $project_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, EventAttendee> $attendees
 * @property-read Collection<int, Reminder> $reminders
 */
class Event extends Model
{
    use Auditable;

    /** @use HasFactory<EventFactory> */
    use HasFactory;

    use SoftDeletes;
    use UsesTenantConnection;

    /**
     * Attributes recorded in the audit trail (see Auditable).
     *
     * @var array<int, string>
     */
    protected array $auditable = ['title', 'type', 'starts_at', 'ends_at', 'recurrence_frequency'];

    public const TYPES = ['event', 'meeting', 'reminder', 'deadline'];

    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];

    /** @var list<string> */
    protected $fillable = [
        'title', 'description', 'location', 'type', 'color',
        'starts_at', 'ends_at', 'all_day',
        'recurrence_frequency', 'recurrence_interval', 'recurrence_by_day',
        'recurrence_until', 'recurrence_count',
        'parent_id', 'original_starts_at', 'is_cancelled',
        'project_id', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'event',
        'color' => '#6366f1',
        'all_day' => false,
        'recurrence_interval' => 1,
        'is_cancelled' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'recurrence_by_day' => 'array',
            'recurrence_until' => 'datetime',
            'original_starts_at' => 'datetime',
            'is_cancelled' => 'boolean',
            'recurrence_interval' => 'integer',
            'recurrence_count' => 'integer',
        ];
    }

    public function isRecurring(): bool
    {
        return $this->recurrence_frequency !== null;
    }

    /**
     * Length of the event in seconds, defaulting to one hour when no end is set,
     * so an occurrence always has a positive duration to render.
     */
    public function durationSeconds(): int
    {
        if ($this->ends_at === null) {
            return 3600;
        }

        return max(0, (int) $this->ends_at->diffInSeconds($this->starts_at, absolute: true));
    }

    public function effectiveEnd(): Carbon
    {
        return $this->ends_at ?? $this->starts_at->copy()->addSeconds($this->durationSeconds());
    }

    /**
     * @return HasMany<EventAttendee, $this>
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class);
    }

    /**
     * @return HasMany<Reminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(Event::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Series masters and one-off events (never exception rows), for expansion.
     *
     * @param Builder<Event> $query
     * @return Builder<Event>
     */
    public function scopeSeriesAndSingles(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Events (or series) that could touch [$from, $to].
     *
     * A recurring master is included regardless of its own start, since its
     * occurrences may fall in the window; one-offs are filtered by their dates.
     *
     * @param Builder<Event> $query
     * @return Builder<Event>
     */
    public function scopeOverlapping(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->where(function (Builder $q) use ($from, $to) {
            $q->whereNotNull('recurrence_frequency')
                ->where('starts_at', '<=', $to)
                ->where(fn (Builder $r) => $r->whereNull('recurrence_until')->orWhere('recurrence_until', '>=', $from));
        })->orWhere(function (Builder $q) use ($from, $to) {
            $q->whereNull('recurrence_frequency')
                ->where('starts_at', '<=', $to)
                ->where(function (Builder $r) use ($from) {
                    $r->whereNull('ends_at')->where('starts_at', '>=', $from->copy()->subDay())
                        ->orWhere('ends_at', '>=', $from);
                });
        });
    }
}
