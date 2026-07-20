<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Organization-scoped roles, seeded into every tenant database.
 *
 * Note: "Super Admin" is deliberately not here — it is a platform-wide
 * capability stored as `users.is_super_admin` in the central database and
 * granted via a Gate::before bypass, since it spans all tenants.
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Employee = 'employee';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Tenant Owner',
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Employee => 'Employee',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * Permissions granted to this role.
     *
     * @return array<int, Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Full control, including billing and deleting the organization.
            self::Owner => Permission::cases(),

            // Everything except billing management (owner-only money decisions).
            self::Admin => array_values(array_filter(
                Permission::cases(),
                fn (Permission $p) => $p !== Permission::BillingManage,
            )),

            // Runs day-to-day work: full CRUD on modules, can invite teammates,
            // but cannot remove members, change settings, or touch billing.
            self::Manager => [
                Permission::CustomersView, Permission::CustomersCreate,
                Permission::CustomersUpdate, Permission::CustomersDelete,
                Permission::CustomersImport, Permission::CustomersExport,
                Permission::ProjectsView, Permission::ProjectsCreate,
                Permission::ProjectsUpdate, Permission::ProjectsDelete,
                Permission::TasksView, Permission::TasksCreate,
                Permission::TasksUpdate, Permission::TasksDelete,
                Permission::CalendarView, Permission::CalendarManage,
                Permission::FilesView, Permission::FilesUpload,
                Permission::FilesDelete, Permission::FilesShare,
                Permission::TeamView, Permission::TeamInvite,
                Permission::SettingsView,
            ],

            // Contributes work; no destructive or administrative actions.
            self::Employee => [
                Permission::CustomersView, Permission::CustomersCreate,
                Permission::CustomersUpdate, Permission::CustomersExport,
                Permission::ProjectsView, Permission::ProjectsCreate,
                Permission::ProjectsUpdate,
                Permission::TasksView, Permission::TasksCreate,
                Permission::TasksUpdate,
                Permission::CalendarView, Permission::CalendarManage,
                Permission::FilesView, Permission::FilesUpload,
                Permission::TeamView,
            ],

            // Strictly read-only.
            self::Viewer => [
                Permission::CustomersView,
                Permission::ProjectsView,
                Permission::TasksView,
                Permission::CalendarView,
                Permission::FilesView,
                Permission::TeamView,
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    public function permissionValues(): array
    {
        return array_map(fn (Permission $p) => $p->value, $this->permissions());
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
