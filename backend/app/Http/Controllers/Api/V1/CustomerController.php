<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\IndexCustomerRequest;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\NoteResource;
use App\Models\Customer;
use App\Repositories\CustomerRepository;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customers CRUD for the active organization.
 *
 * Every action runs inside tenant context (the `tenant` middleware), so records
 * are scoped by database rather than by a where-clause.
 *
 * Authorization is delegated to CustomerPolicy via an explicit authorize() call
 * per action. `authorizeResource()` is deliberately not used: it registers
 * controller middleware, which Laravel 11+ removed from the base controller.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerRepository $repository,
        private readonly CustomerService $service,
    ) {}

    /**
     * Paginated list supporting search, filtering, and sorting.
     *
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Customer>>
     */
    #[OA\Get(
        path: '/api/v1/customers',
        summary: 'List customers in the active organization',
        security: [['sanctum' => []]],
        tags: ['Customers'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'q', in: 'query', description: 'Search name, email, company, or phone', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', description: 'Comma-separated statuses, e.g. active,lead', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tag', in: 'query', description: 'Tag slug', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: Customer::SORTABLE)),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Max 100', schema: new OA\Schema(type: 'integer', maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated customers'),
            new OA\Response(response: 400, description: 'Missing X-Organization header'),
            new OA\Response(response: 403, description: 'Not a member of the organization, or lacks customers.view'),
            new OA\Response(response: 422, description: 'Invalid query parameters', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function index(IndexCustomerRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);

        $customers = $this->repository->paginate($request->filters(), $request->perPage());

        return CustomerResource::collection($customers);
    }

    #[OA\Post(
        path: '/api/v1/customers',
        summary: 'Create a customer',
        security: [['sanctum' => []]],
        tags: ['Customers'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Wayne Enterprises'),
                    new OA\Property(property: 'email', type: 'string', nullable: true),
                    new OA\Property(property: 'phone', type: 'string', nullable: true),
                    new OA\Property(property: 'company', type: 'string', nullable: true),
                    new OA\Property(property: 'website', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: Customer::STATUSES),
                    new OA\Property(property: 'lifetime_value', type: 'number', format: 'float'),
                    new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'), example: ['vip']),
                ],
            ),
        ),
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
            ])),
            new OA\Response(response: 403, description: 'Lacks customers.create'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $customer = $this->service->create($request->validated(), $request->user());

        return response()->json([
            'message' => 'Customer created.',
            'data' => new CustomerResource($customer),
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/customers/{customer}',
        summary: 'One customer, with tags, notes, and attachments',
        security: [['sanctum' => []]],
        tags: ['Customers'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The customer', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Customer')])), new OA\Response(response: 403, description: 'Lacks customers.view'), new OA\Response(response: 404, description: 'Not in this organization')],
    )]
    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $customer->load(['tags', 'notes', 'attachments']);

        return response()->json(['data' => new CustomerResource($customer)]);
    }

    #[OA\Put(
        path: '/api/v1/customers/{customer}',
        summary: 'Update a customer',
        description: 'Partial update. Omitting `tags` leaves them unchanged; sending an empty array removes them all.',
        security: [['sanctum' => []]],
        tags: ['Customers'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'email', type: 'string', nullable: true), new OA\Property(property: 'phone', type: 'string', nullable: true), new OA\Property(property: 'company', type: 'string', nullable: true), new OA\Property(property: 'status', type: 'string', enum: ['lead', 'active', 'inactive', 'churned']), new OA\Property(property: 'lifetime_value', type: 'number', format: 'float'), new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'))])),
        responses: [new OA\Response(response: 200, description: 'Updated'), new OA\Response(response: 403, description: 'Lacks customers.update'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $customer = $this->service->update($customer, $request->validated());

        return response()->json([
            'message' => 'Customer updated.',
            'data' => new CustomerResource($customer),
        ]);
    }

    /**
     * Soft delete — recoverable via restore().
     */
    #[OA\Delete(
        path: '/api/v1/customers/{customer}',
        summary: 'Delete a customer (soft)',
        description: 'Soft delete: recoverable via the restore endpoint.',
        security: [['sanctum' => []]],
        tags: ['Customers'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Deleted'), new OA\Response(response: 403, description: 'Lacks customers.delete')],
    )]
    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        $this->service->delete($customer);

        return response()->json(['message' => 'Customer deleted.']);
    }

    #[OA\Post(
        path: '/api/v1/customers/{id}/restore',
        summary: 'Restore a soft-deleted customer',
        security: [['sanctum' => []]],
        tags: ['Customers'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Restored'), new OA\Response(response: 403, description: 'Lacks customers.delete'), new OA\Response(response: 404, description: 'No such deleted customer')],
    )]
    public function restore(int $id): JsonResponse
    {
        $customer = Customer::onlyTrashed()->findOrFail($id);
        $this->authorize('restore', $customer);

        $this->service->restore($customer);

        return response()->json([
            'message' => 'Customer restored.',
            'data' => new CustomerResource($customer),
        ]);
    }

    /**
     * Export the current filter selection as CSV (streamed).
     */
    #[OA\Get(
        path: '/api/v1/customers/export',
        summary: 'Export the current selection as CSV',
        description: 'Streamed and chunked, so a large organization does not exhaust memory. Accepts the same filters as the list endpoint.',
        security: [['sanctum' => []]],
        tags: ['Customers'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'status', in: 'query', description: 'Comma-separated', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'tag', in: 'query', schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv')), new OA\Response(response: 403, description: 'Lacks customers.export')],
    )]
    public function export(IndexCustomerRequest $request): StreamedResponse
    {
        $this->authorize('export', Customer::class);

        return $this->service->exportCsv($this->repository->query($request->filters()));
    }

    /**
     * Bulk-import customers from a CSV upload.
     */
    #[OA\Post(
        path: '/api/v1/customers/import',
        summary: 'Import customers from a CSV',
        description: 'Rows are validated individually: a bad row is reported and skipped rather than aborting the whole file. A `name` column is required.',
        security: [['sanctum' => []]],
        tags: ['Customers'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['file'], properties: [new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'CSV, max 10 MB')]))),
        responses: [new OA\Response(response: 200, description: 'Import summary: imported, skipped, and per-row errors'), new OA\Response(response: 403, description: 'Lacks customers.import'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function import(Request $request): JsonResponse
    {
        $this->authorize('import', Customer::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'], // 10 MB
        ]);

        $result = $this->service->importCsv(
            $request->file('file')->getRealPath(),
            $request->user(),
        );

        return response()->json([
            'message' => "Imported {$result['imported']} customer(s), skipped {$result['skipped']}.",
            'data' => $result,
        ]);
    }

    /**
     * Attach a note to a customer.
     */
    #[OA\Post(
        path: '/api/v1/customers/{customer}/notes',
        summary: 'Add a note to a customer',
        security: [['sanctum' => []]],
        tags: ['Customers'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['body'], properties: [new OA\Property(property: 'body', type: 'string', maxLength: 5000)])),
        responses: [new OA\Response(response: 201, description: 'Note added'), new OA\Response(response: 403, description: 'Lacks customers.update'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function addNote(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $note = $this->service->addNote($customer, $request->user(), $validated['body']);

        return response()->json([
            'message' => 'Note added.',
            'data' => new NoteResource($note),
        ], 201);
    }
}
