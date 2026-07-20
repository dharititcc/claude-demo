<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The signed-in user's in-app notifications *for the active organization*.
 *
 * Notifications live in the tenant database, so the same central user has a
 * separate inbox per organization — which is what you want: a customer created
 * in Acme should not appear in the user's Globex notifications.
 */
class NotificationController extends Controller
{
    /**
     * List notifications, newest first, with an unread count.
     */
    #[OA\Get(
        path: '/api/v1/notifications',
        summary: 'Your in-app notifications for the active organization',
        description: 'Notifications live in the tenant database, so the same user has a separate inbox per organization: activity in one org never appears in another.',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'limit', in: 'query', description: 'Default 30', schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Notifications, newest first, plus an unread count')],
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->take((int) $request->integer('limit', 30))
            ->get();

        return response()->json([
            'data' => $notifications->map(fn ($n) => [
                'id' => $n->id,
                'event' => $n->data['event'] ?? null,
                'payload' => $n->data['payload'] ?? [],
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'meta' => ['unread' => $user->unreadNotifications()->count()],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/notifications/{id}/read',
        summary: 'Mark one notification as read',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Marked as read'), new OA\Response(response: 404, description: 'Not one of your notifications')],
    )]
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return response()->json(['message' => 'Marked as read.']);
    }

    #[OA\Post(
        path: '/api/v1/notifications/read-all',
        summary: 'Mark every notification as read',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        responses: [new OA\Response(response: 200, description: 'All marked as read')],
    )]
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    #[OA\Delete(
        path: '/api/v1/notifications/{id}',
        summary: 'Remove a notification',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Removed')],
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->delete();

        return response()->json(['message' => 'Notification removed.']);
    }
}
