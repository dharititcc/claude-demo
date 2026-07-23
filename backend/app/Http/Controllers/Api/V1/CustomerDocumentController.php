<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\UploadDocumentRequest;
use App\Http\Resources\CustomerDocumentResource;
use App\Models\Customer;
use App\Models\File;
use App\Services\FileManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Documents filed against a customer.
 *
 * A thin seam over the Files module rather than a second document store:
 * downloading, sharing and deleting still go through the existing
 * /files/{file} routes, which already authorize and stream correctly. Only the
 * customer-shaped operations live here — list, upload, replace, history.
 *
 * Governed by files.* because these are file-manager files. Reading them also
 * needs the customer to be visible, which the route's customer binding and
 * customers.view already establish.
 */
class CustomerDocumentController extends Controller
{
    public function __construct(private readonly FileManagerService $files) {}

    #[OA\Get(
        path: '/api/v1/customers/{customer}/documents',
        summary: "A customer's documents",
        description: 'Current versions only by default — superseded files are reached through the history endpoint. Filterable by category.',
        security: [['sanctum' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string', enum: ['contract', 'invoice', 'proposal', 'report', 'identity', 'other'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Documents, newest first'),
            new OA\Response(response: 403, description: 'Lacks files.view'),
            new OA\Response(response: 404, description: 'Not in this organization'),
        ],
    )]
    public function index(Request $request, Customer $customer): AnonymousResourceCollection
    {
        $this->authorize('viewAny', File::class);

        $documents = $customer->documents()
            // Only the newest version of each document; the rest are history.
            ->current()
            // Eager loaded because the resource asks each row whether it has been
            // replaced — without this that is one query per document.
            ->with('replacedBy')
            ->when(
                in_array($request->query('category'), File::CATEGORIES, true),
                fn ($query) => $query->where('category', $request->query('category')),
            )
            ->orderByDesc('created_at')
            ->get();

        return CustomerDocumentResource::collection($documents);
    }

    #[OA\Post(
        path: '/api/v1/customers/{customer}/documents',
        summary: 'Upload a document for a customer',
        description: 'Goes through the file manager, so the extension deny-list, the storage quota and the tenant-suffixed disk all apply. The stored name is random — the original is kept only for display.',
        security: [['sanctum' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['file'], properties: [
            new OA\Property(property: 'file', type: 'string', format: 'binary'),
            new OA\Property(property: 'category', type: 'string', enum: ['contract', 'invoice', 'proposal', 'report', 'identity', 'other']),
        ]))),
        responses: [
            new OA\Response(response: 201, description: 'Uploaded'),
            new OA\Response(response: 402, description: 'Storage quota reached'),
            new OA\Response(response: 403, description: 'Lacks files.upload'),
            new OA\Response(response: 422, description: 'Blocked file type, or validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(UploadDocumentRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('upload', File::class);

        $document = $this->files->uploadForCustomer(
            $request->file('file'),
            $customer,
            $request->input('category'),
            $request->user(),
        );

        return response()->json([
            'message' => 'Document uploaded.',
            'data' => new CustomerDocumentResource($document->load('replacedBy')),
        ], 201);
    }

    #[OA\Post(
        path: '/api/v1/customers/{customer}/documents/{document}/replace',
        summary: 'Upload a new version of a document',
        description: 'The previous version is kept, not deleted — it becomes history, and the new file records which one it supersedes. Category and customer are inherited so a replacement cannot quietly re-file the document.',
        security: [['sanctum' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'document', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['file'], properties: [
            new OA\Property(property: 'file', type: 'string', format: 'binary'),
        ]))),
        responses: [
            new OA\Response(response: 201, description: 'New version stored'),
            new OA\Response(response: 403, description: 'Lacks files.upload'),
            new OA\Response(response: 404, description: 'Document does not belong to this customer'),
        ],
    )]
    public function replace(UploadDocumentRequest $request, Customer $customer, int $document): JsonResponse
    {
        $this->authorize('upload', File::class);

        $original = $this->documentFor($customer, $document);

        $replacement = $this->files->replace($original, $request->file('file'), $request->user());

        return response()->json([
            'message' => "Version {$replacement->version} uploaded.",
            'data' => new CustomerDocumentResource($replacement->load('replacedBy')),
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/customers/{customer}/documents/{document}/versions',
        summary: 'Every version of a document',
        description: 'The whole chain, newest first, regardless of which version was asked for. Superseded versions remain downloadable through the usual file route.',
        security: [['sanctum' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'document', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Version history'),
            new OA\Response(response: 403, description: 'Lacks files.view'),
            new OA\Response(response: 404, description: 'Document does not belong to this customer'),
        ],
    )]
    public function versions(Customer $customer, int $document): AnonymousResourceCollection
    {
        $this->authorize('viewAny', File::class);

        $file = $this->documentFor($customer, $document);

        return CustomerDocumentResource::collection($this->files->versionHistory($file));
    }

    /**
     * Resolve a document *within* the customer in the path.
     *
     * Scoping the lookup to the customer is what stops
     * /customers/1/documents/99 reaching a document filed under customer 2 —
     * both live in this tenant, so tenancy alone does not prevent it.
     */
    private function documentFor(Customer $customer, int $documentId): File
    {
        /** @var File */
        return $customer->documents()->whereKey($documentId)->firstOrFail();
    }
}
