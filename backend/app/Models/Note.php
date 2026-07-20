<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A free-text note attached to any tenant record.
 *
 * `user_id` refers to the central users table, so it is resolved through
 * `App\Support\CentralUsers` rather than an Eloquent relation — a belongsTo
 * would try to read `users` from the tenant database.
 *
 * @property int $id
 * @property int $user_id
 * @property string $body
 * @property string $notable_type
 * @property int $notable_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Note extends Model
{
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = ['user_id', 'body'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function notable(): MorphTo
    {
        return $this->morphTo();
    }
}
