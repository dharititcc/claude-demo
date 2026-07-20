<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Attachment;
use App\Models\User;

/**
 * File-level permissions, separate from the record they hang off: a user may be
 * allowed to edit a customer without being allowed to attach or remove files.
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $user->can(Permission::FilesView->value);
    }

    public function upload(User $user): bool
    {
        return $user->can(Permission::FilesUpload->value);
    }

    public function delete(User $user): bool
    {
        return $user->can(Permission::FilesDelete->value);
    }
}
