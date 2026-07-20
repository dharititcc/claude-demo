<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use OpenApi\Attributes as OA;

/**
 * Root OpenAPI definition.
 *
 * Kept in one place rather than scattered across controllers so the spec's
 * global concerns — servers, auth schemes, shared schemas — are reviewable in a
 * single file. Endpoint-level annotations live on their controllers.
 *
 * Regenerate with: php artisan l5-swagger:generate
 * Browse at:       /api/documentation
 */
#[OA\Info(
    version: '1.0.0',
    title: 'SaaS Platform API',
    description: <<<'TXT'
    Multi-tenant SaaS platform API.

    **Tenancy.** Each organization owns a dedicated database. Endpoints under
    "Tenant-scoped" require an `X-Organization` header carrying the organization's
    slug or UUID; the caller must be a member of that organization, or the request
    is rejected with 403.

    **Auth.** Obtain a token from `POST /api/v1/auth/login` and send it as
    `Authorization: Bearer <token>`.

    **Permissions.** Roles are organization-scoped, so the same user may hold
    different permissions in different organizations.
    TXT,
)]
#[OA\Server(url: 'http://localhost:8000', description: 'Local development')]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    scheme: 'bearer',
    description: 'Sanctum personal access token.',
)]
#[OA\Tag(name: 'Auth', description: 'Registration, login, password, sessions')]
#[OA\Tag(name: 'Admin', description: 'Super Admin platform management across all organizations')]
#[OA\Tag(name: 'Organizations', description: 'Organizations the caller belongs to, and the active context')]
#[OA\Tag(name: 'Team', description: 'Members, roles, and invitations within the active organization')]
#[OA\Tag(name: 'Dashboard', description: 'Aggregate metrics for the active organization')]
#[OA\Tag(name: 'Customers', description: 'Customer records within the active organization')]
#[OA\Tag(name: 'Projects', description: 'Projects and their members')]
#[OA\Tag(name: 'Tasks', description: 'Tasks, the Kanban board, and time tracking')]
#[OA\Tag(name: 'Calendar', description: 'Events, meetings, and recurring series')]
#[OA\Tag(name: 'Files', description: 'File manager, folders, attachments, and share links')]
#[OA\Tag(name: 'Notifications', description: 'In-app notifications and outbound webhooks')]
#[OA\Tag(name: 'Billing', description: 'Plans, subscriptions, usage, and invoices')]
#[OA\Tag(name: 'Audit', description: "The organization's audit trail")]
class ApiDocs
{
    // Document-level definition only. Reusable component schemas and parameters
    // each live on their own class under App\OpenApi — a class-level #[OA\Schema]
    // means "this class IS the schema", so several on one class register none.
}
