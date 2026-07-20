<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Label;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Task use-cases: creation, Kanban movement, and time tracking.
 */
class TaskService
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, User $actor): Task
    {
        return DB::transaction(function () use ($data, $actor) {
            $task = Task::create([
                ...$this->attributes($data),
                'created_by' => $actor->id,
                // Append to the end of its column unless a position was given.
                'position' => $data['position'] ?? $this->nextPosition(
                    $data['project_id'] ?? null,
                    $data['status'] ?? 'todo',
                ),
            ]);

            if (isset($data['labels'])) {
                $this->syncLabels($task, (array) $data['labels']);
            }

            return $task->load('labels');
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Task $task, array $data): Task
    {
        return DB::transaction(function () use ($task, $data) {
            $task->update($this->attributes($data));

            // Absent means "leave alone"; an empty array means "remove all".
            if (array_key_exists('labels', $data)) {
                $this->syncLabels($task, (array) $data['labels']);
            }

            return $task->load('labels');
        });
    }

    /**
     * Move a task within or between Kanban columns.
     *
     * Positions are floats and a moved card takes the midpoint of its new
     * neighbours, so a drag writes exactly one row instead of renumbering every
     * card below it.
     *
     * @param int|null $beforeId Task the card is dropped above (null = end of column).
     */
    public function move(Task $task, string $status, ?int $beforeId = null): Task
    {
        if (! in_array($status, Task::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => __('That is not a valid column.'),
            ]);
        }

        return DB::transaction(function () use ($task, $status, $beforeId) {
            $position = $this->positionFor($task, $status, $beforeId);

            $task->update(['status' => $status, 'position' => $position]);

            return $task->refresh();
        });
    }

    /**
     * Start a timer.
     *
     * One running timer per user, enforced here: MySQL has no partial unique
     * index, so "at most one row per user with ended_at IS NULL" cannot be a
     * database constraint. Any previous timer is stopped rather than rejected —
     * a user starting work on B has finished with A, and refusing would just
     * make them stop it manually first.
     */
    public function startTimer(Task $task, User $user, ?string $description = null): TimeEntry
    {
        return DB::transaction(function () use ($task, $user, $description) {
            $this->stopRunningTimers($user);

            return TimeEntry::create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'description' => $description,
                'started_at' => now(),
            ]);
        });
    }

    /**
     * Stop the user's running timer on this task.
     */
    public function stopTimer(Task $task, User $user): TimeEntry
    {
        $entry = TimeEntry::where('task_id', $task->id)
            ->where('user_id', $user->id)
            ->running()
            ->latest('started_at')
            ->first();

        if ($entry === null) {
            throw ValidationException::withMessages([
                'timer' => __('No timer is running on this task.'),
            ]);
        }

        return DB::transaction(function () use ($entry, $task) {
            $this->close($entry);
            $task->recalculateTrackedTime();

            return $entry->refresh();
        });
    }

    /**
     * Log time after the fact, without running a timer.
     */
    public function logTime(Task $task, User $user, int $minutes, ?string $description = null, bool $billable = true): TimeEntry
    {
        if ($minutes <= 0) {
            throw ValidationException::withMessages([
                'minutes' => __('Logged time must be greater than zero.'),
            ]);
        }

        return DB::transaction(function () use ($task, $user, $minutes, $description, $billable) {
            $entry = TimeEntry::create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'description' => $description,
                // Back-date the start so the entry occupies real time on a
                // timeline rather than being a zero-length blip at "now".
                'started_at' => now()->subMinutes($minutes),
                'ended_at' => now(),
                'seconds' => $minutes * 60,
                'billable' => $billable,
            ]);

            $task->recalculateTrackedTime();

            return $entry;
        });
    }

    /**
     * The user's currently running timer, if any.
     */
    public function runningTimer(User $user): ?TimeEntry
    {
        return TimeEntry::with('task')
            ->where('user_id', $user->id)
            ->running()
            ->latest('started_at')
            ->first();
    }

    /**
     * Attach labels by name, creating any that are new.
     *
     * @param array<int, string> $names
     */
    public function syncLabels(Task $task, array $names): void
    {
        $ids = collect($names)
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->map(fn (string $name) => Label::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name],
            )->id)
            ->all();

        $task->labels()->sync($ids);
    }

    private function stopRunningTimers(User $user): void
    {
        $running = TimeEntry::where('user_id', $user->id)->running()->get();

        foreach ($running as $entry) {
            $this->close($entry);
            $entry->task?->recalculateTrackedTime();
        }
    }

    private function close(TimeEntry $entry): void
    {
        $endedAt = now();

        $entry->forceFill([
            'ended_at' => $endedAt,
            'seconds' => max(0, (int) $endedAt->diffInSeconds($entry->started_at, absolute: true)),
        ])->save();
    }

    /**
     * Midpoint between the card above the drop point and the one below it.
     */
    private function positionFor(Task $task, string $status, ?int $beforeId): float
    {
        $column = Task::where('project_id', $task->project_id)
            ->where('status', $status)
            ->whereKeyNot($task->id)
            ->orderBy('position');

        if ($beforeId === null) {
            // Dropped at the end of the column.
            $last = (clone $column)->max('position');

            return ($last ?? 0.0) + Task::POSITION_GAP;
        }

        $before = (clone $column)->whereKey($beforeId)->first();

        if ($before === null) {
            return ((clone $column)->max('position') ?? 0.0) + Task::POSITION_GAP;
        }

        $above = (clone $column)->where('position', '<', $before->position)->max('position');

        // Dropped at the top: halve the first position rather than going
        // negative, which keeps the column's ordering positive indefinitely.
        return $above === null
            ? $before->position / 2
            : ($above + $before->position) / 2;
    }

    private function nextPosition(?int $projectId, string $status): float
    {
        $last = Task::where('project_id', $projectId)->where('status', $status)->max('position');

        return ($last ?? 0.0) + Task::POSITION_GAP;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'project_id', 'parent_id', 'title', 'description', 'status',
            'priority', 'assignee_id', 'due_on', 'estimated_minutes',
        ]));
    }
}
