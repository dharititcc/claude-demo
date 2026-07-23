<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreContactRequest;
use App\Http\Requests\Customer\UpdateContactRequest;
use App\Http\Resources\CustomerContactResource;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Services\CustomerContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * People at a customer company.
 *
 * Nested under the customer because a contact has no meaning without one: the
 * route binding proves the contact belongs to the customer in the path, so no
 * handler has to re-check it (see contactFor()).
 */
class CustomerContactController extends Controller
{
    public function __construct(private readonly CustomerContactService $contacts) {}

    #[OA\Get(
        path: '/api/v1/customers/{customer}/contacts',
        summary: 'Contacts at a customer',
        description: 'Primary contact first, then alphabetical. Searchable across name, email, job title and department. Not paginated: a company has tens of contacts, not thousands, and the detail screen shows them all.',
        security: [['sanctum' => []]],
        tags: ['Customers'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'q', in: 'query', description: 'Search name, email, job title, department', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Contacts'),
            new OA\Response(response: 403, description: 'Lacks customers.view'),
            new OA\Response(response: 404, description: 'Not in this organization'),
        ],
    )]
    public function index(Request $request, Customer $customer): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CustomerContact::class);

        $contacts = $customer->contacts()
            ->search($request->string('q')->toString() ?: null)
            ->when(
                in_array($request->query('status'), CustomerContact::STATUSES, true),
                fn ($query) => $query->where('status', $request->query('status')),
            )
            ->ordered()
            ->get();

        return CustomerContactResource::collection($contacts);
    }

    #[OA\Post(
        path: '/api/v1/customers/{customer}/contacts',
        summary: 'Add a contact',
        description: 'The first contact of a customer becomes primary automatically — a company with one contact and no primary is not a state worth allowing. Marking a contact primary demotes whoever held it, in one transaction.',
        security: [['sanctum' => []]],
        tags: ['Customers'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['first_name'], properties: [
            new OA\Property(property: 'first_name', type: 'string'),
            new OA\Property(property: 'last_name', type: 'string', nullable: true),
            new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
            new OA\Property(property: 'phone', type: 'string', nullable: true),
            new OA\Property(property: 'mobile', type: 'string', nullable: true),
            new OA\Property(property: 'department', type: 'string', nullable: true),
            new OA\Property(property: 'job_title', type: 'string', nullable: true),
            new OA\Property(property: 'notes', type: 'string', nullable: true),
            new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive']),
            new OA\Property(property: 'is_primary', type: 'boolean'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 403, description: 'Lacks customers.update'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreContactRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('create', CustomerContact::class);

        $contact = $this->contacts->create($customer, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Contact added.',
            'data' => new CustomerContactResource($contact),
        ], 201);
    }

    #[OA\Put(
        path: '/api/v1/customers/{customer}/contacts/{contact}',
        summary: 'Edit a contact',
        description: 'Partial edit. Setting is_primary true promotes this contact and demotes the previous one; setting it false hands primary to the next contact rather than leaving the customer with none.',
        security: [['sanctum' => []]],
        tags: ['Customers'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'contact', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 403, description: 'Lacks customers.update'),
            new OA\Response(response: 404, description: 'Contact does not belong to this customer'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function update(UpdateContactRequest $request, Customer $customer, int $contact): JsonResponse
    {
        $model = $this->contactFor($customer, $contact);

        $this->authorize('update', $model);

        $updated = $this->contacts->update($model, $request->validated());

        return response()->json([
            'message' => 'Contact updated.',
            'data' => new CustomerContactResource($updated),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/customers/{customer}/contacts/{contact}',
        summary: 'Remove a contact',
        description: 'Soft delete — a contact removed by mistake is a person whose history is worth recovering. Removing the primary hands primary to the next contact.',
        security: [['sanctum' => []]],
        tags: ['Customers'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'contact', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 403, description: 'Lacks customers.update'),
            new OA\Response(response: 404, description: 'Contact does not belong to this customer'),
        ],
    )]
    public function destroy(Customer $customer, int $contact): JsonResponse
    {
        $model = $this->contactFor($customer, $contact);

        $this->authorize('delete', $model);

        $this->contacts->delete($model);

        return response()->json(['message' => 'Contact removed.']);
    }

    /**
     * Resolve a contact *within* the customer in the path.
     *
     * Scoping the lookup to the customer rather than looking the contact up by
     * id alone is what stops /customers/1/contacts/99 reading a contact that
     * belongs to customer 2 — both are in this tenant, so tenancy alone does
     * not prevent it.
     */
    private function contactFor(Customer $customer, int $contactId): CustomerContact
    {
        /** @var CustomerContact */
        return $customer->contacts()->whereKey($contactId)->firstOrFail();
    }
}
