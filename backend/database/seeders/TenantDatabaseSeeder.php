<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Runs inside a freshly-provisioned tenant database (see TenancyServiceProvider).
 * Seeds the organization-scoped roles and permissions.
 */
class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The permission cache is keyed globally; clear it so the newly created
        // tenant's roles are not masked by another tenant's cached set.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::findOrCreate($roleEnum->value, 'web');
            $role->syncPermissions($roleEnum->permissionValues());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
