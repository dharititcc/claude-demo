<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A public share link for a file. The token is stored hashed; only the URL holds
 * the plaintext.
 *
 * @property int $id
 * @property int $file_id
 * @property string $token_hash
 * @property Carbon|null $expires_at
 * @property string|null $password_hash
 * @property int $download_count
 * @property int|null $max_downloads
 * @property int|null $created_by
 * @property-read File $file
 */
class FileShare extends Model
{
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = ['file_id', 'token_hash', 'expires_at', 'password_hash', 'max_downloads', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['token_hash', 'password_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isValid(): bool
    {
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return $this->max_downloads === null || $this->download_count < $this->max_downloads;
    }

    public function requiresPassword(): bool
    {
        return $this->password_hash !== null;
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
