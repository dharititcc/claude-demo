<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\InviteMemberRequest;
use App\Http\Requests\Member\UpdateMemberRoleRequest;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\MemberResource;
use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationService;
use App\Services\OrganizationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Team membership for the active organization.
 *
 * Membership lives in the central `organization_user` pivot, while each member's
 * role lives in the tenant's own database — so listing the team means joining
 * across two databases by hand rather than with a single Eloquent relation.
 */
class MemberController extends Controller
{
    public function __construct(
        private readonly OrganizationService $organizations,
        private readonly InvitationService $invitations,
    ) {}

    #[OA\Get(
        path: '/api/v1/members',
        summary: 'Members of the active organization',
        description: 'Each member is shown with the role they hold HERE. The same person may hold a different role in another organization.',
        security: [['sanctum' => []]],
        tags: ['Team'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        responses: [new OA\Response(response: 200, description: 'Members with their roles'), new OA\Response(response: 403, description: 'Lacks team.view')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewTeam', tenant());

        $members = tenant()->members()->get();

        // Roles are stored per-tenant, so resolve them in one query inside the
        // tenant context rather than N queries (one per member).
        $rolesByUser = $this->rolesFor($members->pluck('id')->all());

        $members->each(function (User $user) use ($rolesByUser) {
            $user->setAttribute('organization_role', $rolesByUser[$user->id] ?? null);
        });

        return response()->json([
            'data' => MemberResource::collection($members),
        ]);
    }

    /**
     * Invite someone by email. They may or may not already have an account.
     */
    #[OA\Post(
        path: '/api/v1/members/invitations',
        summary: 'Invite someone by email',
        description: 'Sends an emailed link with a single-use token valid for 7 days. Re-inviting the same address replaces the previous invitation. Owner can only be granted by an owner.',
        security: [['sanctum' => []]],
        tags: ['Team'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['email', 'role'], properties: [new OA\Property(property: 'email', type: 'string', format: 'email'), new OA\Property(property: 'role', type: 'string', enum: ['owner', 'admin', 'manager', 'employee', 'viewer'])])),
        responses: [new OA\Response(response: 201, description: 'Invitation sent'), new OA\Response(response: 403, description: 'Lacks team.invite'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function invite(InviteMemberRequest $request): JsonResponse
    {
        $this->authorize('inviteTeam', tenant());

        $validated = $request->validated();

        [$invitation] = $this->invitations->invite(
            tenant(),
            $validated['email'],
            RoleEnum::from($validated['role']),
            $request->user(),
        );

        return response()->json([
            'message' => 'Invitation sent.',
            'data' => new InvitationResource($invitation),
        ], 201);
    }

    /**
     * Pending invitations for this organization.
     */
    #[OA\Get(
        path: '/api/v1/members/invitations',
        summary: 'Pending invitations',
        security: [['sanctum' => []]],
        tags: ['Team'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        responses: [new OA\Response(response: 200, description: 'Pending invitations (never includes the token)'), new OA\Response(response: 403, description: 'Lacks team.view')],
    )]
    public function invitations(Request $request): JsonResponse
    {
        $this->authorize('viewTeam', tenant());

        $invitations = Invitation::where('tenant_id', tenant()->id)
            ->pending()
            ->latest()
            ->get();

        return response()->json(['data' => InvitationResource::collection($invitations)]);
    }

    #[OA\Delete(
        path: '/api/v1/members/invitations/{invitation}',
        summary: 'Revoke a pending invitation',
        security: [['sanctum' => []]],
        tags: ['Team'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'invitation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Invitation revoked'), new OA\Response(response: 403, description: 'Lacks team.invite')],
    )]
    public function revokeInvitation(Request $request, int $invitation): JsonResponse
    {
        $this->authorize('inviteTeam', tenant());

        $this->invitations->revoke(tenant(), $invitation);

        return response()->json(['message' => 'Invitation revoked.']);
    }

    /**
     * Change a member's role within this organization.
     */
    #[OA\Put(
        path: '/api/v1/members/{user}/role',
        summary: 'Change a member role in this organization',
        description: 'An organization must always keep at least one owner; demoting the last one is refused.',
        security: [['sanctum' => []]],
        tags: ['Team'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['role'], properties: [new OA\Property(property: 'role', type: 'string', enum: ['owner', 'admin', 'manager', 'employee', 'viewer'])])),
        responses: [new OA\Response(response: 200, description: 'Role updated'), new OA\Response(response: 403, description: 'Lacks team.update/team.remove'), new OA\Response(response: 404, description: 'Not a member'), new OA\Response(response: 422, description: 'Would leave the organization without an owner')],
    )]
    public function updateRole(UpdateMemberRoleRequest $request, int $user): JsonResponse
    {
        $this->authorize('manageTeam', tenant());

        $validated = $request->validated();

        $member = $this->memberOrFail($user);

        $this->guardLastOwner($member, changingTo: RoleEnum::from($validated['role']));

        $this->organizations->addMember(
            tenant(),
            $member,
            RoleEnum::from($validated['role']),
            isOwner: $validated['role'] === RoleEnum::Owner->value,
        );

        return response()->json(['message' => 'Role updated.']);
    }

    /**
     * Remove a member from this organization.
     */
    #[OA\Delete(
        path: '/api/v1/members/{user}',
        summary: 'Remove a member from this organization',
        description: 'Their role here is revoked; their other organizations are untouched. You cannot remove yourself, nor the last owner.',
        security: [['sanctum' => []]],
        tags: ['Team'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Member removed'), new OA\Response(response: 403, description: 'Lacks team.update/team.remove'), new OA\Response(response: 404, description: 'Not a member'), new OA\Response(response: 422, description: 'Cannot remove yourself or the last owner')],
    )]
    public function destroy(Request $request, int $user): JsonResponse
    {
        $this->authorize('manageTeam', tenant());

        $member = $this->memberOrFail($user);

        if ($member->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => __('You cannot remove yourself from an organization.'),
            ]);
        }

        $this->guardLastOwner($member);

        $this->organizations->removeMember(tenant(), $member);

        return response()->json(['message' => 'Member removed.']);
    }

    private function memberOrFail(int $userId): User
    {
        $member = tenant()->members()->whereKey($userId)->first();

        abort_if($member === null, 404, 'That user is not a member of this organization.');

        return $member;
    }

    /**
     * An organization must always retain at least one owner, or nobody can
     * manage billing or the organization itself.
     */
    private function guardLastOwner(User $member, ?RoleEnum $changingTo = null): void
    {
        if ($changingTo === RoleEnum::Owner) {
            return; // promoting to owner never reduces the owner count
        }

        if (! $member->hasRole(RoleEnum::Owner->value)) {
            return;
        }

        $ownerCount = count($this->usersWithRole(RoleEnum::Owner->value));

        if ($ownerCount <= 1) {
            throw ValidationException::withMessages([
                'user' => __('This is the only owner. Promote another member to owner first.'),
            ]);
        }
    }

    /**
     * User ids holding a role in the current tenant.
     *
     * Queries the pivot directly rather than using Spatie's `$role->users`
     * relation: that relation would resolve the User model on the *tenant*
     * connection, where the central `users` table does not exist.
     *
     * @return array<int, int>
     */
    private function usersWithRole(string $role): array
    {
        return $this->roleAssignments()
            ->where('roles.name', $role)
            ->pluck('model_has_roles.model_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Map user id => role name, resolved from the tenant database in one query.
     *
     * @param array<int, int> $userIds
     * @return array<int, string>
     */
    private function rolesFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return $this->roleAssignments()
            ->whereIn('model_has_roles.model_id', $userIds)
            ->pluck('roles.name', 'model_has_roles.model_id')
            ->mapWithKeys(fn (string $name, $id) => [(int) $id => $name])
            ->all();
    }

    /**
     * Role assignments for users in the active tenant's database.
     *
     * @return Builder
     */
    private function roleAssignments()
    {
        $tables = config('permission.table_names');

        // DB::table() uses the default connection, which tenancy has already
        // pointed at the active tenant's database.
        return DB::table($tables['model_has_roles'])
            ->join($tables['roles'], "{$tables['roles']}.id", '=', "{$tables['model_has_roles']}.role_id")
            ->where("{$tables['model_has_roles']}.model_type", (new User)->getMorphClass());
    }
}
