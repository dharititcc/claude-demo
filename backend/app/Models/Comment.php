<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A comment on any tenant record. Polymorphic so projects, tasks, and whatever
 * comes next share one implementation.
 *
 * `user_id` refers to the central users table, so it is resolved through the
 * author map the resources build rather than an Eloquent relation.
 *
 * @property int $id
 * @property int $user_id
 * @property string $body
 * @property string $commentable_type
 * @property int $commentable_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Comment extends Model
{
    use SoftDeletes;
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = ['user_id', 'body'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
