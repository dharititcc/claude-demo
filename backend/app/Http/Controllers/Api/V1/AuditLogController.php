<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * The organization's audit trail.
 *
 * Entries live in the tenant database (see the Activity model), so this is
 * naturally scoped to one organization. Records are read-only by design — an
 * audit log you can edit is not an audit log.
 */
class AuditLogController extends Controller
{
    #[OA\Get(
        path: '/api/v1/audit-logs',
        summary: 'The organization audit trail',
        description: 'Read-only by design: an audit log you can edit is not an audit log. Entries live in the tenant database, so the trail is naturally scoped to one organization. Updates record only the fields that actually changed.',
        security: [['sanctum' => []]],
        tags: ['Audit'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'log_name', in: 'query', description: 'Table name, e.g. customers', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'event', in: 'query', description: 'created, updated, deleted, restored', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'causer_id', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Paginated audit entries, newest first'), new OA\Response(response: 403, description: 'Lacks audit.view'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function index(Request $request): JsonResponse
    {
        // Pass the active tenant instance, not the class: the policy method takes
        // a Tenant, and passing the class name leaves that argument unfilled.
        $this->authorize('viewAudit', tenant());

        $filters = $request->validate([
            'log_name' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', Rule::in(['created', 'updated', 'deleted', 'restored'])],
            'causer_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Order by id, not created_at: the log is high-volume and many entries
        // share a second, so created_at alone gives an undefined order between
        // them. The id is monotonic and reflects true insertion order.
        $query = Activity::query()->orderByDesc('id');

        if (! empty($filters['log_name'])) {
            $query->where('log_name', $filters['log_name']);
        }

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        if (! empty($filters['causer_id'])) {
            $query->where('causer_id', $filters['causer_id']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $logs = $query->paginate($filters['per_page'] ?? 30)->withQueryString();

        return response()->json([
            'data' => $logs->getCollection()->map(fn (Activity $log) => [
                'id' => $log->id,
                'log_name' => $log->log_name,
                'description' => $log->description,
                'event' => $log->event,
                'subject_type' => class_basename($log->subject_type ?? ''),
                'subject_id' => $log->subject_id,
                // causer is a central user; only the id is stored (no cross-DB FK).
                'causer_id' => $log->causer_id,
                'changes' => $log->properties->toArray(),
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
