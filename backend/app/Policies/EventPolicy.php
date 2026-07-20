<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Event;
use App\Models\User;

/**
 * The calendar shares the Calendar permissions with meetings/reminders: they are
 * one surface to the user, so a single view/manage pair governs all of it.
 */
class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CalendarView->value);
    }

    public function view(User $user, Event $event): bool
    {
        return $user->can(Permission::CalendarView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CalendarManage->value);
    }

    public function update(User $user, Event $event): bool
    {
        return $user->can(Permission::CalendarManage->value);
    }

    public function delete(User $user, Event $event): bool
    {
        return $user->can(Permission::CalendarManage->value);
    }
}
