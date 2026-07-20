<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every permission an organization member can hold. Permissions are seeded
 * into each tenant database, so grants are always organization-scoped.
 *
 * Naming: `<resource>.<action>`
 */
enum Permission: string
{
    // ─── Customers ───
    case CustomersView = 'customers.view';
    case CustomersCreate = 'customers.create';
    case CustomersUpdate = 'customers.update';
    case CustomersDelete = 'customers.delete';
    case CustomersImport = 'customers.import';
    case CustomersExport = 'customers.export';

    // ─── Projects ───
    case ProjectsView = 'projects.view';
    case ProjectsCreate = 'projects.create';
    case ProjectsUpdate = 'projects.update';
    case ProjectsDelete = 'projects.delete';

    // ─── Tasks ───
    case TasksView = 'tasks.view';
    case TasksCreate = 'tasks.create';
    case TasksUpdate = 'tasks.update';
    case TasksDelete = 'tasks.delete';

    // ─── Calendar ───
    case CalendarView = 'calendar.view';
    case CalendarManage = 'calendar.manage';

    // ─── Files ───
    case FilesView = 'files.view';
    case FilesUpload = 'files.upload';
    case FilesDelete = 'files.delete';
    case FilesShare = 'files.share';

    // ─── Team ───
    case TeamView = 'team.view';
    case TeamInvite = 'team.invite';
    case TeamUpdate = 'team.update';
    case TeamRemove = 'team.remove';

    // ─── Billing ───
    case BillingView = 'billing.view';
    case BillingManage = 'billing.manage';

    // ─── Organization settings ───
    case SettingsView = 'settings.view';
    case SettingsUpdate = 'settings.update';

    // ─── Audit ───
    case AuditView = 'audit.view';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
