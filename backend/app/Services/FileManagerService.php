<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\File;
use App\Models\FileShare;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * File manager operations: uploads (quota-enforced), folder tree, and shares.
 */
class FileManagerService
{
    /** Same executable/script deny-list as record attachments — stored XSS risk. */
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com', 'js', 'jar', 'html', 'htm', 'svg',
    ];

    public function __construct(private readonly UsageService $usage) {}

    /**
     * Store an uploaded file, enforcing the organization's storage quota.
     *
     * @throws ValidationException
     */
    public function upload(UploadedFile $upload, ?int $folderId, User $actor): File
    {
        $extension = strtolower((string) $upload->getClientOriginalExtension());

        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => ["Files of type .{$extension} are not allowed."],
            ]);
        }

        $this->assertWithinQuota($upload->getSize());

        // store() generates a random name — never trust the client filename as a
        // path; the original is kept only as a display name.
        $path = $upload->store('files', 'public');

        return File::create([
            'folder_id' => $folderId,
            'name' => $upload->getClientOriginalName(),
            'disk' => 'public',
            'path' => $path,
            'mime_type' => $upload->getClientMimeType(),
            'size' => $upload->getSize(),
            'created_by' => $actor->id,
        ]);
    }

    /**
     * File a document against a customer.
     *
     * Delegates to upload() so the extension block-list, the storage quota, the
     * random storage name and the tenant-suffixed disk all still apply — none of
     * that is re-implemented here.
     */
    public function uploadForCustomer(UploadedFile $upload, Customer $customer, ?string $category, User $actor): File
    {
        $file = $this->upload($upload, null, $actor);

        $file->forceFill([
            'customer_id' => $customer->getKey(),
            'category' => $category,
        ])->save();

        return $file->refresh();
    }

    /**
     * Supersede a document with a newer version.
     *
     * The old row is kept and left in place: it is the history. The new file
     * points at it through replaces_id, which is what makes the old one stop
     * being "current" — see File::scopeCurrent(). Nothing is deleted, so a
     * replacement can always be traced back.
     *
     * Category and customer are inherited rather than re-supplied: a replacement
     * that quietly re-filed a document somewhere else would be a surprise.
     */
    public function replace(File $original, UploadedFile $upload, User $actor): File
    {
        return DB::transaction(function () use ($original, $upload, $actor) {
            $replacement = $this->upload($upload, $original->folder_id, $actor);

            $replacement->forceFill([
                'customer_id' => $original->customer_id,
                'category' => $original->category,
                'version' => $original->version + 1,
                'replaces_id' => $original->getKey(),
            ])->save();

            return $replacement->refresh();
        });
    }

    /**
     * Every version of a document, newest first.
     *
     * Walks the chain from whichever version was asked for, so the history is
     * the same regardless of which link the caller holds.
     *
     * @return Collection<int, File>
     */
    public function versionHistory(File $file): Collection
    {
        // Walk back to the original.
        $root = $file;

        while ($root->replaces_id !== null) {
            $previous = File::withTrashed()->find($root->replaces_id);

            if ($previous === null) {
                break;
            }

            $root = $previous;
        }

        // Then forward, collecting every version in the chain.
        $chain = collect([$root]);
        $cursor = $root;

        while (($next = File::withTrashed()->where('replaces_id', $cursor->getKey())->first()) !== null) {
            $chain->push($next);
            $cursor = $next;
        }

        return $chain->sortByDesc('version')->values();
    }

    public function createFolder(string $name, ?int $parentId, User $actor): Folder
    {
        return Folder::create([
            'name' => $name,
            'parent_id' => $parentId,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Create a share link and return [share, plaintextToken].
     *
     * The token exists only in the returned URL; the database keeps a hash.
     *
     * @return array{0: FileShare, 1: string}
     */
    public function share(File $file, User $actor, ?int $expiresInDays = null, ?string $password = null, ?int $maxDownloads = null): array
    {
        $token = Str::random(40);

        $share = FileShare::create([
            'file_id' => $file->id,
            'token_hash' => FileShare::hashToken($token),
            'expires_at' => $expiresInDays !== null ? now()->addDays($expiresInDays) : null,
            'password_hash' => $password !== null ? bcrypt($password) : null,
            'max_downloads' => $maxDownloads,
            'created_by' => $actor->id,
        ]);

        return [$share, $token];
    }

    /**
     * Resolve a share token to a still-valid share, or fail.
     *
     * @throws ValidationException
     */
    public function resolveShare(string $token): FileShare
    {
        $share = FileShare::with('file')->where('token_hash', FileShare::hashToken($token))->first();

        if ($share === null || ! $share->isValid()) {
            throw ValidationException::withMessages([
                'token' => __('This share link is invalid, expired, or has reached its download limit.'),
            ]);
        }

        return $share;
    }

    /**
     * Atomically claim one download slot against the share's cap.
     *
     * The cap check (isValid()) and the counter increment used to be two separate
     * statements, so N concurrent downloads could all pass the check before any
     * increment landed and blow straight past max_downloads. This collapses the
     * claim into a single conditional UPDATE — it increments only while still
     * under the cap, and the affected-row count says whether we actually got a
     * slot. Call it *before* streaming the bytes.
     *
     * @throws ValidationException
     */
    public function claimDownload(FileShare $share): void
    {
        $claimed = FileShare::whereKey($share->getKey())
            ->where(fn (Builder $q) => $q->whereNull('max_downloads')
                ->orWhereColumn('download_count', '<', 'max_downloads'))
            ->update(['download_count' => DB::raw('download_count + 1')]);

        if ($claimed === 0) {
            throw ValidationException::withMessages([
                'token' => __('This share link is invalid, expired, or has reached its download limit.'),
            ]);
        }
    }

    /**
     * Current storage use in bytes (from the denormalised usage report's MB).
     */
    public function storageUsedBytes(): int
    {
        return (int) File::sum('size');
    }

    /**
     * @throws ValidationException
     */
    private function assertWithinQuota(int $incomingBytes): void
    {
        $plan = $this->usage->planFor(tenant());
        $limitMb = $plan?->limitFor('storage_mb');

        if ($limitMb === null) {
            return; // unlimited
        }

        $usedBytes = $this->storageUsedBytes();
        $limitBytes = $limitMb * 1_048_576;

        if ($usedBytes + $incomingBytes > $limitBytes) {
            throw ValidationException::withMessages([
                'file' => [sprintf(
                    'This upload would exceed your %d MB storage limit (%d MB used).',
                    $limitMb,
                    (int) ceil($usedBytes / 1_048_576),
                )],
            ])->status(402); // Payment Required — prompt an upgrade, not "denied"
        }
    }
}
