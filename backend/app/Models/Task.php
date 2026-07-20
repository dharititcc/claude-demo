<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A task, optionally belonging to a project and optionally a subtask of another
 * task.
 *
 * @property int $id
 * @property int|null $project_id
 * @property int|null $parent_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property string $priority
 * @property int|null $assignee_id
 * @property int|null $created_by
 * @property Carbon|null $due_on
 * @property Carbon|null $completed_at
 * @property float $position
 * @property int $tracked_seconds
 * @property int|null $estimated_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Task> $subtasks
 * @property-read Collection<int, Label> $labels
 * @property-read Collection<int, TimeEntry> $timeEntries
 * @property-read Task|null $parent
 * @property-read Project|null $project
 */
class Task extends Model
{
    use Auditable;

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use SoftDeletes;
    use UsesTenantConnection;

    /**
     * Attributes recorded in the audit trail (see Auditable).
     *
     * @var array<int, string>
     */
    protected array $auditable = ['title', 'status', 'priority', 'assignee_id', 'due_on', 'project_id'];

    /** Kanban columns, in board order. */
    public const STATUSES = ['todo', 'in_progress', 'review', 'done'];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public const SORTABLE = ['title', 'status', 'priority', 'due_on', 'position', 'created_at'];

    /** Gap between positions when appending, leaving room to drop between. */
    public const POSITION_GAP = 1000.0;

    /** @var list<string> */
    protected $fillable = [
        'project_id', 'parent_id', 'title', 'description', 'status', 'priority',
        'assignee_id', 'created_by', 'due_on', 'position', 'estimated_minutes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'todo',
        'priority' => 'medium',
        'position' => 0,
        'tracked_seconds' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'position' => 'float',
            'tracked_seconds' => 'integer',
            'estimated_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Task $task) {
            // Derive completed_at from status so the two cannot disagree.
            if ($task->isDirty('status')) {
                $task->completed_at = $task->status === 'done'
                    ? ($task->completed_at ?? now())
                    : null;
            }
        });

        // Cascade soft deletes to subtasks. The database FK cascade only fires on
        // a hard delete, so without this a soft-deleted parent would leave its
        // subtasks behind — and they would resurface at the root of the board,
        // since their now-hidden parent no longer nests them.
        static::deleting(function (Task $task) {
            if ($task->isForceDeleting()) {
                return; // the FK cascade handles hard deletes
            }

            $task->subtasks()->get()->each->delete();
        });

        static::restoring(function (Task $task) {
            // Bring subtasks back with their parent, so a restore is the inverse
            // of the delete above rather than leaving them orphaned-and-trashed.
            $task->subtasks()->onlyTrashed()->get()->each->restore();
        });
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id')->orderBy('position');
    }

    /**
     * @return BelongsToMany<Label, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class)->withTimestamps();
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isOverdue(): bool
    {
        return $this->due_on !== null && $this->due_on->isPast() && $this->status !== 'done';
    }

    /**
     * Recompute tracked time from the entries.
     *
     * Denormalised onto the task so rendering a board does not sum time_entries
     * per card. Only closed entries count — a running timer has no duration yet.
     */
    public function recalculateTrackedTime(): void
    {
        $this->forceFill([
            'tracked_seconds' => (int) $this->timeEntries()->whereNotNull('ended_at')->sum('seconds'),
        ])->saveQuietly();
    }

    /**
     * @param Builder<Task> $query
     * @return Builder<Task>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
        $like = "%{$escaped}%";

        return $query->where(fn (Builder $q) => $q
            ->where('title', 'like', $like)
            ->orWhere('description', 'like', $like));
    }

    /**
     * Top-level tasks only — subtasks are shown nested under their parent.
     *
     * @param Builder<Task> $query
     * @return Builder<Task>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }
}
