<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\File;
use App\Models\User;

/**
 * The file manager reuses the Files permissions already seeded for record
 * attachments — it is the same capability (view/upload/delete/share files),
 * exposed as a standalone document space rather than hanging off a record.
 */
class FilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::FilesView->value);
    }

    public function view(User $user, File $file): bool
    {
        return $user->can(Permission::FilesView->value);
    }

    public function upload(User $user): bool
    {
        return $user->can(Permission::FilesUpload->value);
    }

    public function delete(User $user, ?File $file = null): bool
    {
        return $user->can(Permission::FilesDelete->value);
    }

    public function share(User $user): bool
    {
        return $user->can(Permission::FilesShare->value);
    }
}
