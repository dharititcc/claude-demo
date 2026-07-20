<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A stored file attached to any tenant record. The bytes live on a
 * tenant-suffixed disk (see FilesystemTenancyBootstrapper), so one
 * organization's uploads are never served from another's directory.
 *
 * @property int $id
 * @property int $user_id
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string|null $mime_type
 * @property int $size
 * @property string $attachable_type
 * @property int $attachable_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Attachment extends Model
{
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = ['user_id', 'disk', 'path', 'filename', 'mime_type', 'size'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Keep the bytes and the row in step: deleting the record removes the file.
        static::deleted(function (Attachment $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
