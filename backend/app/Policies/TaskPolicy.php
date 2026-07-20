<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::TasksView->value);
    }

    public function view(User $user, Task $task): bool
    {
        return $user->can(Permission::TasksView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::TasksCreate->value);
    }

    public function update(User $user, Task $task): bool
    {
        return $user->can(Permission::TasksUpdate->value);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->can(Permission::TasksDelete->value);
    }

    /**
     * Anyone who may edit a task may track time against it — logging your own
     * work is not a privileged action, and requiring a separate permission would
     * mean employees could do the work but not record it.
     */
    public function trackTime(User $user, Task $task): bool
    {
        return $user->can(Permission::TasksUpdate->value);
    }
}
