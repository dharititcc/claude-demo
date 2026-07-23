<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\ActivityController as AdminActivityController;
use App\Http\Controllers\Api\V1\Admin\ImpersonationController as AdminImpersonationController;
use App\Http\Controllers\Api\V1\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\V1\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\CustomerContactController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerDocumentController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\ImpersonationController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\PublicShareController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TimeEntryController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Three tiers of access:
|   1. Public          — no token (register, login, password reset).
|   2. Authenticated   — valid token, central database context.
|   3. Tenant-scoped   — additionally requires an X-Organization header; the
|                        `tenant` middleware boots that organization's database
|                        after authentication has already resolved the user.
|
*/

Route::prefix('v1')->group(function () {

    // ─── Public ───────────────────────────────────────────────
    Route::post('auth/register', RegisterController::class)
        ->middleware('throttle:6,1')
        ->name('auth.register');

    Route::post('auth/login', [LoginController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    // The second half of a 2FA sign-in. Public because the caller has passed the
    // password but holds no token yet — that is the state this resolves. The
    // per-challenge attempt ceiling in AuthService is the real brake; this
    // throttle only blunts a single address grinding through challenges.
    Route::post('auth/2fa/challenge', TwoFactorChallengeController::class)
        ->middleware('throttle:10,1')
        ->name('auth.2fa.challenge');

    Route::post('auth/forgot-password', ForgotPasswordController::class)
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::post('auth/reset-password', ResetPasswordController::class)
        ->middleware('throttle:6,1')
        ->name('password.store');

    // Signed link clicked from the user's mailbox — no bearer token present.
    Route::get('auth/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    // Public preview so the SPA can show "Join Acme" before the invitee signs in.
    Route::get('invitations/{token}', [InvitationController::class, 'show'])
        ->middleware('throttle:20,1')
        ->name('invitations.show');

    // Public file shares — no auth. Tenancy is resolved from the org slug in the
    // path (see PublicShareController), since a visitor sends no token or header.
    Route::get('public/shares/{organization}/{token}', [PublicShareController::class, 'show'])
        ->middleware('throttle:30,1')
        ->name('shares.show');
    Route::post('public/shares/{organization}/{token}/download', [PublicShareController::class, 'download'])
        ->middleware('throttle:30,1')
        ->name('shares.download');

    // ─── Authenticated (central context) ──────────────────────
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('auth/me', MeController::class)->name('auth.me');
        Route::post('auth/logout', [LoginController::class, 'destroy'])->name('auth.logout');
        Route::put('auth/password', [PasswordController::class, 'update'])->name('auth.password.update');

        Route::post('auth/email/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:6,1')
            ->name('verification.send');

        // Two-factor enrolment and management. Throttled because enable/confirm
        // are code-guessing surfaces too, and regenerating codes is a password
        // check that should not be grindable.
        Route::get('auth/2fa', [TwoFactorController::class, 'show'])->name('auth.2fa.show');
        Route::post('auth/2fa/enable', [TwoFactorController::class, 'enable'])
            ->middleware('throttle:10,1')
            ->name('auth.2fa.enable');
        Route::post('auth/2fa/confirm', [TwoFactorController::class, 'confirm'])
            ->middleware('throttle:10,1')
            ->name('auth.2fa.confirm');
        Route::delete('auth/2fa', [TwoFactorController::class, 'destroy'])
            ->middleware('throttle:10,1')
            ->name('auth.2fa.destroy');
        Route::get('auth/2fa/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])
            ->name('auth.2fa.recovery-codes');
        Route::post('auth/2fa/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
            ->middleware('throttle:10,1')
            ->name('auth.2fa.recovery-codes.store');

        Route::get('auth/sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::delete('auth/sessions/others', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
        Route::delete('auth/sessions/{id}', [SessionController::class, 'destroy'])->name('sessions.destroy');
        Route::get('auth/login-history', [SessionController::class, 'loginHistory'])->name('sessions.history');

        Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');

        // Accepting happens *outside* tenant scope: the invitee is not a member
        // yet, so the tenant middleware would reject them before they can join.
        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])
            ->middleware('throttle:10,1')
            ->name('invitations.accept');

        // ─── Super Admin: platform organization management ────
        //
        // Central-context on purpose: these read across every organization, so
        // they must NOT sit inside the `tenant` group below. `super-admin` is
        // the gate; a non-super-admin gets a 404 (the surface is not advertised).
        // `stats` is registered before `{organization}` so the literal path is
        // not swallowed by the wildcard.
        Route::middleware('super-admin')->prefix('admin')->name('admin.')->group(function () {
            Route::get('organizations', [AdminOrganizationController::class, 'index'])->name('organizations.index');
            Route::get('organizations/stats', [AdminOrganizationController::class, 'stats'])->name('organizations.stats');
            // withTrashed: an admin must be able to inspect and restore a
            // soft-deleted org, so binding has to reach past the default scope.
            Route::get('organizations/{organization}', [AdminOrganizationController::class, 'show'])->withTrashed()->name('organizations.show');
            Route::put('organizations/{organization}', [AdminOrganizationController::class, 'update'])->name('organizations.update');
            Route::put('organizations/{organization}/limits', [AdminOrganizationController::class, 'limits'])->name('organizations.limits');
            Route::post('organizations/{organization}/suspend', [AdminOrganizationController::class, 'suspend'])->name('organizations.suspend');
            Route::post('organizations/{organization}/activate', [AdminOrganizationController::class, 'activate'])->name('organizations.activate');
            Route::delete('organizations/{organization}', [AdminOrganizationController::class, 'destroy'])->name('organizations.destroy');
            Route::post('organizations/{organization}/restore', [AdminOrganizationController::class, 'restore'])->withTrashed()->name('organizations.restore');

            // Start impersonating a member of an organization.
            Route::post('organizations/{organization}/impersonate', [AdminImpersonationController::class, 'start'])->name('organizations.impersonate');

            // ─── Plan master: the subscription catalogue ───
            // Central and platform-wide, like everything else under `admin`.
            Route::get('plans', [AdminPlanController::class, 'index'])->name('plans.index');
            Route::post('plans', [AdminPlanController::class, 'store'])->name('plans.store');
            Route::get('plans/{plan}', [AdminPlanController::class, 'show'])->whereNumber('plan')->name('plans.show');
            Route::put('plans/{plan}', [AdminPlanController::class, 'update'])->whereNumber('plan')->name('plans.update');
            Route::delete('plans/{plan}', [AdminPlanController::class, 'destroy'])->whereNumber('plan')->name('plans.destroy');

            // The central audit trail of everything above.
            Route::get('activity', [AdminActivityController::class, 'index'])->name('activity.index');
        });

        // Ending an impersonation is done BY the impersonated session, which is
        // not a super admin — so it sits here, outside the admin gate.
        Route::post('impersonation/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');

        // ─── Tenant-scoped (requires X-Organization) ──────────
        Route::middleware('tenant')->group(function () {
            Route::get('context', [OrganizationController::class, 'context'])->name('organizations.context');

            Route::get('dashboard', DashboardController::class)->name('dashboard');

            // ─── Billing ───
            Route::get('billing', [BillingController::class, 'overview'])->name('billing.overview');
            Route::get('billing/plans', [BillingController::class, 'plans'])->name('billing.plans');
            Route::get('billing/invoices', [BillingController::class, 'invoices'])->name('billing.invoices');
            Route::get('billing/invoices/{invoice}', [BillingController::class, 'downloadInvoice'])->name('billing.invoice');
            Route::get('billing/setup-intent', [BillingController::class, 'setupIntent'])->name('billing.setup-intent');
            Route::post('billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
            Route::put('billing/subscription', [BillingController::class, 'swap'])->name('billing.swap');
            Route::delete('billing/subscription', [BillingController::class, 'cancel'])->name('billing.cancel');
            Route::post('billing/subscription/resume', [BillingController::class, 'resume'])->name('billing.resume');

            // ─── Organization settings ───
            // POST rather than PUT: the logo is a multipart upload, and PHP does
            // not populate $_FILES for PUT requests.
            Route::post('organization', [OrganizationController::class, 'update'])->name('organizations.update');

            // ─── Team ───
            Route::get('members', [MemberController::class, 'index'])->name('members.index');
            Route::post('members/invitations', [MemberController::class, 'invite'])
                ->middleware('limit:users')
                ->name('members.invite');
            Route::get('members/invitations', [MemberController::class, 'invitations'])->name('members.invitations');
            Route::delete('members/invitations/{invitation}', [MemberController::class, 'revokeInvitation'])
                ->whereNumber('invitation')
                ->name('members.invitations.revoke');
            Route::put('members/{user}/role', [MemberController::class, 'updateRole'])
                ->whereNumber('user')
                ->name('members.role');
            Route::delete('members/{user}', [MemberController::class, 'destroy'])
                ->whereNumber('user')
                ->name('members.destroy');

            // ─── Projects ───
            Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::get('projects/{project}', [ProjectController::class, 'show'])->whereNumber('project')->name('projects.show');
            Route::put('projects/{project}', [ProjectController::class, 'update'])->whereNumber('project')->name('projects.update');
            Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->whereNumber('project')->name('projects.destroy');
            Route::post('projects/{id}/restore', [ProjectController::class, 'restore'])->whereNumber('id')->name('projects.restore');

            // ─── Tasks ───
            // The board and running-timer routes are static and must precede the
            // {task} wildcard, or "board" would bind as a task id.
            Route::get('tasks/board', [TaskController::class, 'board'])->name('tasks.board');
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
            Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
            Route::get('tasks/{task}', [TaskController::class, 'show'])->whereNumber('task')->name('tasks.show');
            Route::put('tasks/{task}', [TaskController::class, 'update'])->whereNumber('task')->name('tasks.update');
            Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->whereNumber('task')->name('tasks.destroy');
            Route::put('tasks/{task}/move', [TaskController::class, 'move'])->whereNumber('task')->name('tasks.move');

            // ─── Calendar ───
            // index returns expanded occurrences for a window; the rest act on rows.
            Route::get('events', [EventController::class, 'index'])->name('events.index');
            Route::post('events', [EventController::class, 'store'])->name('events.store');
            Route::get('events/{event}', [EventController::class, 'show'])->whereNumber('event')->name('events.show');
            Route::put('events/{event}', [EventController::class, 'update'])->whereNumber('event')->name('events.update');
            Route::delete('events/{event}', [EventController::class, 'destroy'])->whereNumber('event')->name('events.destroy');
            Route::put('events/{event}/occurrence', [EventController::class, 'updateOccurrence'])->whereNumber('event')->name('events.occurrence');

            // ─── File manager ───
            Route::get('files', [FileController::class, 'index'])->name('files.index');
            Route::post('files', [FileController::class, 'upload'])->name('files.upload');
            Route::get('files/{file}/download', [FileController::class, 'download'])->whereNumber('file')->name('files.download');
            Route::delete('files/{file}', [FileController::class, 'destroy'])->whereNumber('file')->name('files.destroy');
            Route::post('files/{file}/share', [FileController::class, 'share'])->whereNumber('file')->name('files.share');
            Route::post('folders', [FileController::class, 'createFolder'])->name('folders.store');
            Route::delete('folders/{folder}', [FileController::class, 'deleteFolder'])->whereNumber('folder')->name('folders.destroy');

            // ─── Notifications (in-app) ───
            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
            Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
            Route::delete('notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

            // ─── Webhooks ───
            Route::get('webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
            Route::post('webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
            Route::put('webhooks/{webhook}', [WebhookController::class, 'update'])->whereNumber('webhook')->name('webhooks.update');
            Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])->whereNumber('webhook')->name('webhooks.destroy');
            Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])->whereNumber('webhook')->name('webhooks.deliveries');

            // ─── Audit log (read-only) ───
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');

            // ─── Time tracking ───
            Route::get('timer/running', [TimeEntryController::class, 'running'])->name('timer.running');
            Route::get('tasks/{task}/time', [TimeEntryController::class, 'index'])->whereNumber('task')->name('tasks.time.index');
            Route::post('tasks/{task}/time/start', [TimeEntryController::class, 'start'])->whereNumber('task')->name('tasks.time.start');
            Route::post('tasks/{task}/time/stop', [TimeEntryController::class, 'stop'])->whereNumber('task')->name('tasks.time.stop');
            Route::post('tasks/{task}/time/log', [TimeEntryController::class, 'log'])->whereNumber('task')->name('tasks.time.log');
            Route::delete('tasks/{task}/time/{entry}', [TimeEntryController::class, 'destroy'])->whereNumber(['task', 'entry'])->name('tasks.time.destroy');

            // ─── Attachments ───
            Route::post('customers/{customer}/attachments', [AttachmentController::class, 'store'])
                ->whereNumber('customer')
                ->name('customers.attachments.store');
            Route::delete('customers/{customer}/attachments/{attachment}', [AttachmentController::class, 'destroy'])
                ->whereNumber(['customer', 'attachment'])
                ->name('customers.attachments.destroy');

            // Static segments must be declared before the {customer} wildcard,
            // otherwise "export"/"import" would bind as a customer id.
            Route::get('customers/export', [CustomerController::class, 'export'])->name('customers.export');
            Route::post('customers/import', [CustomerController::class, 'import'])->name('customers.import');
            Route::post('customers/{id}/restore', [CustomerController::class, 'restore'])
                ->whereNumber('id')
                ->name('customers.restore');
            // ─── Customer invoices ───
            // The organization's own sales documents, governed by invoices.* —
            // nothing to do with /billing, which is what this organization pays
            // us for the platform.
            Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])
                ->whereNumber('invoice')->name('invoices.show');
            Route::post('customers/{customer}/invoices', [InvoiceController::class, 'store'])
                ->whereNumber('customer')->name('customers.invoices.store');
            Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])
                ->whereNumber('invoice')->name('invoices.update');
            Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])
                ->whereNumber('invoice')->name('invoices.send');
            Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])
                ->whereNumber('invoice')->name('invoices.payments.store');
            Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])
                ->whereNumber('invoice')->name('invoices.void');
            Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])
                ->whereNumber('invoice')->name('invoices.destroy');

            // ─── Customer documents ───
            // A seam over the Files module: downloading, sharing and deleting
            // still use /files/{file}, which already authorizes and streams.
            Route::get('customers/{customer}/documents', [CustomerDocumentController::class, 'index'])
                ->whereNumber('customer')->name('customers.documents.index');
            Route::post('customers/{customer}/documents', [CustomerDocumentController::class, 'store'])
                ->whereNumber('customer')->name('customers.documents.store');
            Route::post('customers/{customer}/documents/{document}/replace', [CustomerDocumentController::class, 'replace'])
                ->whereNumber(['customer', 'document'])->name('customers.documents.replace');
            Route::get('customers/{customer}/documents/{document}/versions', [CustomerDocumentController::class, 'versions'])
                ->whereNumber(['customer', 'document'])->name('customers.documents.versions');

            // ─── Customer contacts ───
            // Nested: a contact has no meaning outside its customer, and the
            // nesting is what scopes the lookup so one customer's contact id
            // cannot be read through another customer's path.
            Route::get('customers/{customer}/contacts', [CustomerContactController::class, 'index'])
                ->whereNumber('customer')->name('customers.contacts.index');
            Route::post('customers/{customer}/contacts', [CustomerContactController::class, 'store'])
                ->whereNumber('customer')->name('customers.contacts.store');
            Route::put('customers/{customer}/contacts/{contact}', [CustomerContactController::class, 'update'])
                ->whereNumber(['customer', 'contact'])->name('customers.contacts.update');
            Route::delete('customers/{customer}/contacts/{contact}', [CustomerContactController::class, 'destroy'])
                ->whereNumber(['customer', 'contact'])->name('customers.contacts.destroy');

            Route::post('customers/{customer}/notes', [CustomerController::class, 'addNote'])
                ->whereNumber('customer')
                ->name('customers.notes.store');

            // `limit:customers` enforces the plan quota. Applied only to store —
            // reads, updates, and deletes must keep working at the ceiling, or an
            // organization that fills its plan could not delete rows to get back
            // under it.
            Route::post('customers', [CustomerController::class, 'store'])
                ->middleware('limit:customers')
                ->name('customers.store');

            Route::apiResource('customers', CustomerController::class)
                ->except(['store'])
                ->whereNumber('customer');
        });
    });
});
