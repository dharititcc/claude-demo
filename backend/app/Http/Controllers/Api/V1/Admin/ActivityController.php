<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexActivityRequest;
use App\Http\Resources\Admin\AdminActivityResource;
use App\Models\AdminActivity;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The central Super Admin audit trail — who did what to which organization.
 *
 * Read-only: this log is written only by AdminAudit as a side effect of admin
 * actions, and there is no endpoint to edit or delete an entry. An audit log you
 * can rewrite is not an audit log.
 */
class ActivityController extends Controller
{
    #[OA\Get(
        path: '/api/v1/admin/activity',
        summary: 'Super Admin audit trail',
        description: 'Every suspend, edit, delete, restore, and purge, newest first. Central and cross-org — distinct from the per-organization activity log that lives inside each tenant database. Filterable by action and by target organization.',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(name: 'action', in: 'query', description: 'e.g. organization.suspended', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'organization', in: 'query', description: 'Filter by target organization id', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated audit entries'),
            new OA\Response(response: 404, description: 'Not a super admin'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function index(IndexActivityRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = AdminActivity::query()
            ->with('admin')
            // Order by id, not created_at: the log is high-volume and many
            // entries share a second, so created_at alone leaves ties unordered.
            // The id is monotonic and reflects true insertion order.
            ->orderByDesc('id');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['organization'])) {
            $query->where('target_type', 'organization')
                ->where('target_id', $filters['organization']);
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 30))->withQueryString();

        return AdminActivityResource::collection($page->getCollection())
            ->additional([
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ])
            ->response();
    }
}
