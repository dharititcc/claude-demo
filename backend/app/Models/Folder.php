<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A folder in the file-manager tree.
 *
 * `path` is a materialised path of ancestor ids ("/1/4/") maintained on save, so
 * "everything under this folder" is one prefix query rather than a recursive
 * walk.
 *
 * @property int $id
 * @property string $name
 * @property int|null $parent_id
 * @property string $path
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Folder> $children
 * @property-read Collection<int, File> $files
 * @property-read Folder|null $parent
 */
class Folder extends Model
{
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = ['name', 'parent_id', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['path' => '/'];

    protected static function booted(): void
    {
        static::saving(function (Folder $folder) {
            // Recompute the materialised path whenever the parent changes.
            if ($folder->isDirty('parent_id') || ! $folder->exists) {
                $parent = $folder->parent_id !== null ? Folder::find($folder->parent_id) : null;
                $folder->path = $parent === null ? '/' : $parent->path.$parent->id.'/';
            }
        });
    }

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    /**
     * @return HasMany<Folder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    /**
     * @return HasMany<File, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }
}
