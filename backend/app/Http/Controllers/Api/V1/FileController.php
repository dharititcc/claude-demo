<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\File\CreateFolderRequest;
use App\Http\Requests\File\CreateShareRequest;
use App\Http\Requests\File\UploadFileRequest;
use App\Models\File;
use App\Models\Folder;
use App\Services\FileManagerService;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The file manager: browse folders, upload/download files, and create shares.
 *
 * Reuses the Files permissions (files.view/upload/delete/share) already seeded
 * for record attachments — it is the same capability, a different surface.
 */
class FileController extends Controller
{
    public function __construct(
        private readonly FileManagerService $files,
        private readonly UsageService $usage,
    ) {}

    /**
     * Contents of a folder (or the root when no folder is given), plus a storage
     * summary for the quota bar.
     */
    #[OA\Get(
        path: '/api/v1/files',
        summary: 'Browse a folder',
        description: 'Contents of a folder (root when folder_id is omitted), a breadcrumb built from the materialised path, and a storage summary for the quota bar.',
        security: [['sanctum' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'folder_id', in: 'query', description: 'Omit for the root folder', schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Folders, files, breadcrumb, and storage usage'), new OA\Response(response: 403, description: 'Lacks files.view')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', File::class);

        $folderId = $request->integer('folder_id') ?: null;

        $folders = Folder::where('parent_id', $folderId)->orderBy('name')->get();
        $files = File::where('folder_id', $folderId)->latest()->get();

        $usage = $this->usage->report(tenant())['storage_mb'];

        return response()->json([
            'data' => [
                'folder_id' => $folderId,
                'breadcrumb' => $this->breadcrumb($folderId),
                'folders' => $folders->map(fn (Folder $f) => [
                    'id' => $f->id,
                    'name' => $f->name,
                ]),
                'files' => $files->map(fn (File $f) => [
                    'id' => $f->id,
                    'name' => $f->name,
                    'mime_type' => $f->mime_type,
                    'size' => $f->size,
                    'created_at' => $f->created_at?->toIso8601String(),
                ]),
            ],
            'meta' => ['storage' => $usage],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/files',
        summary: 'Upload a file',
        description: 'Enforces the plan storage quota (402 when it would be exceeded) and refuses executable or script types regardless of the MIME type claimed. The stored path is generated, never the client filename.',
        security: [['sanctum' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['file'], properties: [new OA\Property(property: 'file', type: 'string', format: 'binary'), new OA\Property(property: 'folder_id', type: 'integer', nullable: true)]))),
        responses: [new OA\Response(response: 201, description: 'Uploaded'), new OA\Response(response: 402, description: 'Would exceed the plan storage quota'), new OA\Response(response: 403, description: 'Lacks files.upload'), new OA\Response(response: 422, description: 'Blocked file type or too large')],
    )]
    public function upload(UploadFileRequest $request): JsonResponse
    {
        $this->authorize('upload', File::class);

        $file = $this->files->upload(
            $request->file('file'),
            $request->integer('folder_id') ?: null,
            $request->user(),
        );

        return response()->json([
            'message' => 'File uploaded.',
            'data' => ['id' => $file->id, 'name' => $file->name, 'size' => $file->size],
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/files/{file}/download',
        summary: 'Download a file',
        security: [['sanctum' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'file', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The file'), new OA\Response(response: 403, description: 'Lacks files.view'), new OA\Response(response: 404, description: 'Not in this organization')],
    )]
    public function download(Request $request, File $file): StreamedResponse
    {
        $this->authorize('viewAny', File::class);

        return Storage::disk($file->disk)->download($file->path, $file->name);
    }

    #[OA\Delete(
        path: '/api/v1/files/{file}',
        summary: 'Delete a file (soft)',
        description: 'Soft delete: the bytes stay until the record is force-deleted, so a restore does not hand back a row pointing at nothing.',
        security: [['sanctum' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'file', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Deleted'), new OA\Response(response: 403, description: 'Lacks files.delete')],
    )]
    public function destroy(File $file): JsonResponse
    {
        $this->authorize('delete', File::class);

        $file->delete(); // soft delete; the file stays until force-deleted

        return response()->json(['message' => 'File deleted.']);
    }

    #[OA\Post(
        path: '/api/v1/folders',
        summary: 'Create a folder',
        security: [['sanctum' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'parent_id', type: 'integer', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 403, description: 'Lacks files.upload'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function createFolder(CreateFolderRequest $request): JsonResponse
    {
        $this->authorize('upload', File::class);

        $validated = $request->validated();

        $folder = $this->files->createFolder(
            $validated['name'],
            $validated['parent_id'] ?? null,
            $request->user(),
        );

        return response()->json([
            'message' => 'Folder created.',
            'data' => ['id' => $folder->id, 'name' => $folder->name],
        ], 201);
    }

    #[OA\Delete(
        path: '/api/v1/folders/{folder}',
        summary: 'Delete a folder and its subtree',
        security: [['sanctum' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'folder', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Deleted'), new OA\Response(response: 403, description: 'Lacks files.delete')],
    )]
    public function deleteFolder(Folder $folder): JsonResponse
    {
        $this->authorize('delete', File::class);

        $folder->delete(); // cascade removes the subtree

        return response()->json(['message' => 'Folder deleted.']);
    }

    /**
     * Create a share link for a file.
     */
    #[OA\Post(
        path: '/api/v1/files/{file}/share',
        summary: 'Create a public share link',
        description: 'The token is returned only in the URL and stored hashed. The organization slug is part of the link because a public visitor sends no header that could say which tenant database holds the share.',
        security: [['sanctum' => []]],
        tags: ['Files'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'file', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'expires_in_days', type: 'integer', minimum: 1, maximum: 365, nullable: true, description: 'Omit for no expiry'), new OA\Property(property: 'password', type: 'string', nullable: true, description: 'Optional gate'), new OA\Property(property: 'max_downloads', type: 'integer', nullable: true, description: 'Omit for unlimited')])),
        responses: [new OA\Response(response: 201, description: 'Share link created'), new OA\Response(response: 403, description: 'Lacks files.share'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function share(CreateShareRequest $request, File $file): JsonResponse
    {
        $this->authorize('share', File::class);

        $validated = $request->validated();

        [$share, $token] = $this->files->share(
            $file,
            $request->user(),
            $validated['expires_in_days'] ?? null,
            $validated['password'] ?? null,
            $validated['max_downloads'] ?? null,
        );

        return response()->json([
            'message' => 'Share link created.',
            'data' => [
                'id' => $share->id,
                // The token alone cannot say which tenant database holds the
                // share, so the organization slug is part of the public URL. The
                // token is the only place the plaintext appears.
                'url' => rtrim((string) config('app.frontend_url'), '/').'/share/'.tenant()->slug."/{$token}",
                'expires_at' => $share->expires_at?->toIso8601String(),
                'requires_password' => $share->requiresPassword(),
            ],
        ], 201);
    }

    /**
     * Ancestor folders from root to the current one, for a breadcrumb.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function breadcrumb(?int $folderId): array
    {
        if ($folderId === null) {
            return [];
        }

        $folder = Folder::find($folderId);

        if ($folder === null) {
            return [];
        }

        // The materialised path holds the ancestor ids; one query fetches them.
        $ids = array_filter(explode('/', $folder->path));
        $ancestors = Folder::whereIn('id', $ids)->get()->keyBy('id');

        $crumb = [];
        foreach ($ids as $id) {
            if ($ancestor = $ancestors->get((int) $id)) {
                $crumb[] = ['id' => $ancestor->id, 'name' => $ancestor->name];
            }
        }
        $crumb[] = ['id' => $folder->id, 'name' => $folder->name];

        return $crumb;
    }
}
