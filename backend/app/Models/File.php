<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /** @var list<string> */
    protected $fillable = ['folder_id', 'name', 'disk', 'path', 'mime_type', 'size', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer'];
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
}
