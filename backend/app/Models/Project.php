<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A project in the active organization's database.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $status
 * @property string $color
 * @property int|null $customer_id
 * @property int|null $owner_id
 * @property Carbon|null $starts_on
 * @property Carbon|null $due_on
 * @property Carbon|null $completed_at
 * @property string|null $budget
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Task> $tasks
 * @property-read Collection<int, ProjectMember> $members
 * @property-read Customer|null $customer
 */
class Project extends Model
{
    use Auditable;

    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use SoftDeletes;
    use UsesTenantConnection;

    /**
     * Attributes recorded in the audit trail (see Auditable).
     *
     * @var array<int, string>
     */
    protected array $auditable = ['name', 'status', 'customer_id', 'owner_id', 'due_on', 'budget'];

    public const STATUSES = ['planning', 'active', 'on_hold', 'completed', 'cancelled'];

    public const SORTABLE = ['name', 'status', 'due_on', 'created_at', 'updated_at'];

    /** @var list<string> */
    protected $fillable = [
        'name', 'slug', 'description', 'status', 'color',
        'customer_id', 'owner_id', 'starts_on', 'due_on', 'budget',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'planning',
        'color' => '#6366f1',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'budget' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Project $project) {
            if (blank($project->slug)) {
                $project->slug = Str::slug($project->name);
            }

            // Keep completed_at in step with status rather than trusting callers
            // to set both — otherwise "completed" projects with no completion
            // date accumulate and reporting quietly breaks.
            if ($project->isDirty('status')) {
                $project->completed_at = $project->status === 'completed'
                    ? ($project->completed_at ?? now())
                    : null;
            }
        });
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Top-level tasks only — subtasks hang off their parent and would otherwise
     * appear twice on a board.
     *
     * @return HasMany<Task, $this>
     */
    public function rootTasks(): HasMany
    {
        return $this->hasMany(Task::class)->whereNull('parent_id');
    }

    /**
     * @return HasMany<ProjectMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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

    /**
     * Progress as a percentage of completed tasks.
     *
     * Reads from loaded counts when present so a list view does not fire a query
     * per project; returns 0 rather than dividing by zero on an empty project.
     */
    public function progress(): int
    {
        $total = $this->tasks_count ?? $this->tasks()->count();

        if ($total === 0) {
            return 0;
        }

        $done = $this->completed_tasks_count ?? $this->tasks()->where('status', 'done')->count();

        return (int) round(($done / $total) * 100);
    }

    public function isOverdue(): bool
    {
        return $this->due_on !== null
            && $this->due_on->isPast()
            && ! in_array($this->status, ['completed', 'cancelled'], true);
    }

    /**
     * @param Builder<Project> $query
     * @return Builder<Project>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        // Escape LIKE wildcards so a literal % does not match every row.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
        $like = "%{$escaped}%";

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', $like)
            ->orWhere('description', 'like', $like));
    }
}
