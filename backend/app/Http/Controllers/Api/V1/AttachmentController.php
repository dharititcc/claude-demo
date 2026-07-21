<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attachment\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * File attachments on customers.
 *
 * Files are written to the tenant-suffixed disk configured by
 * FilesystemTenancyBootstrapper, so one organization's uploads can never be
 * read from another's storage path.
 */
class AttachmentController extends Controller
{
    /** Rejected outright regardless of the reported MIME type. */
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com', 'js', 'jar', 'html', 'htm', 'svg',
    ];

    #[OA\Post(
        path: '/api/v1/customers/{customer}/attachments',
        summary: 'Attach a file to a customer',
        description: 'Executable and script types are refused regardless of the MIME type claimed, because the browser-reported type is attacker-controlled and an .html or .svg served from our origin is stored XSS. The stored path is generated; the original filename is kept only as a label.',
        security: [['sanctum' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['file'], properties: [new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'Max 10 MB')]))),
        responses: [new OA\Response(response: 201, description: 'Uploaded'), new OA\Response(response: 403, description: 'Lacks files.upload'), new OA\Response(response: 422, description: 'Blocked file type or too large')],
    )]
    public function store(StoreAttachmentRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);
        $this->authorize('upload', Attachment::class);

        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());

        // Deny-list executable and script types. `mimes:` alone is not enough:
        // the browser-reported type is attacker-controlled, and an .html or .svg
        // served from our origin is a stored-XSS vector.
        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['file' => ["Files of type .{$extension} are not allowed."]],
            ], 422);
        }

        // store() generates a random name — never trust the client's filename as
        // a path, and keep the original only as a display label.
        $path = $file->store('attachments', 'public');

        $attachment = new Attachment([
            'user_id' => $request->user()->id,
            'disk' => 'public',
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        $customer->attachments()->save($attachment);

        return response()->json([
            'message' => 'File uploaded.',
            'data' => new AttachmentResource($attachment),
        ], 201);
    }

    #[OA\Delete(
        path: '/api/v1/customers/{customer}/attachments/{attachment}',
        summary: 'Delete an attachment',
        description: 'Scoped to the customer, so an id from another record cannot be deleted. Removes the stored bytes as well as the row.',
        security: [['sanctum' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'attachment', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Deleted'), new OA\Response(response: 403, description: 'Lacks files.delete'), new OA\Response(response: 404, description: 'Not an attachment of this customer')],
    )]
    public function destroy(Request $request, Customer $customer, int $attachment): JsonResponse
    {
        $this->authorize('update', $customer);
        $this->authorize('delete', Attachment::class);

        // Scope to the customer so an id from another record cannot be deleted.
        $record = $customer->attachments()->whereKey($attachment)->firstOrFail();

        $record->delete(); // model event removes the stored file

        return response()->json(['message' => 'Attachment deleted.']);
    }
}
