<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A standalone document in the file manager (distinct from a polymorphic
 * Attachment, which hangs off a record).
 *
 * The bytes live on a tenant-suffixed disk (FilesystemTenancyBootstrapper), so
 * one organization's files are never served from another's directory.
 *
 * @property int $id
 * @property int|null $folder_id
 * @property int|null $customer_id
 * @property string|null $category
 * @property int $version
 * @property int|null $replaces_id
 * @property string $name
 * @property string $disk
 * @property string $path
 * @property string|null $mime_type
 * @property int $size
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $deleted_at
 * @property-read Folder|null $folder
 */
class File extends Model
{
    use SoftDeletes;
    use UsesTenantConnection;

    /**
     * `version` and `replaces_id` are absent on purpose: the version chain is
     * state the application owns, set by FileManagerService::replace(). A client
     * that could post its own version could rewrite a document's history.
     *
     * @var list<string>
     */
    protected $fillable = [
        'folder_id', 'customer_id', 'name', 'category', 'disk', 'path',
        'mime_type', 'size', 'created_by',
    ];

    /** Categories a customer document may be filed under. */
    public const CATEGORIES = ['contract', 'invoice', 'proposal', 'report', 'identity', 'other'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        // Remove the bytes on a *force* delete only. A soft delete keeps the file
        // recoverable — deleting the object here would make "restore" hand back a
        // row pointing at nothing.
        static::forceDeleted(function (File $file) {
            Storage::disk($file->disk)->delete($file->path);
        });
    }

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /**
     * @return HasMany<FileShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(FileShare::class);
    }

    /**
     * Whether a browser can safely show this inline.
     *
     * An allow-list, not a block-list: anything not named here is downloaded
     * instead. Inline rendering of an unexpected type is how a document becomes
     * a stored-XSS vector, so the default has to be "no".
     */
    public function isPreviewable(): bool
    {
        return in_array($this->mime_type, [
            'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain',
        ], true);
    }

    /**
     * The customer this document is filed under, if any.
     *
     * Nullable: the file manager also holds documents that belong to the
     * organization rather than to one customer.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The version this file supersedes.
     *
     * @return BelongsTo<File, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(File::class, 'replaces_id');
    }

    /**
     * The newer version that superseded this one, if any.
     *
     * @return HasOne<File, $this>
     */
    public function replacedBy(): HasOne
    {
        return $this->hasOne(File::class, 'replaces_id');
    }

    /**
     * Only the newest version of each document.
     *
     * Derived from the chain rather than a stored flag: the current version is
     * simply the one nothing else replaces, so it cannot fall out of step with
     * reality the way an is_current column could.
     *
     * @param Builder<File> $query
     * @return Builder<File>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereDoesntHave('replacedBy');
    }
}
