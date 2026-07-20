<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $email
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property bool $successful
 * @property string|null $reason
 * @property Carbon|null $attempted_at
 */
class LoginHistory extends Model
{
    use UsesCentralConnection;

    /**
     * This table records a point-in-time event via `attempted_at`; it has no
     * created_at/updated_at columns to maintain.
     */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'email',
        'ip_address',
        'user_agent',
        'successful',
        'reason',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'attempted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
