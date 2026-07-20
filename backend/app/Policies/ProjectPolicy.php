<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;

/**
 * Permissions resolve from the active tenant's database, so the same user may
 * manage projects in one organization and only read them in another.
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ProjectsView->value);
    }

    public function view(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ProjectsCreate->value);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsUpdate->value);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsDelete->value);
    }

    public function restore(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsDelete->value);
    }
}
