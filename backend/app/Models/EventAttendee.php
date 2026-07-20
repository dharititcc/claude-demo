<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An attendee's response to an event. `user_id` is a central users.id, so it is
 * resolved through the caller's author map rather than an Eloquent relation.
 *
 * @property int $id
 * @property int $event_id
 * @property int $user_id
 * @property string $response
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EventAttendee extends Model
{
    use UsesTenantConnection;

    public const RESPONSES = ['pending', 'accepted', 'declined', 'tentative'];

    /** @var list<string> */
    protected $fillable = ['event_id', 'user_id', 'response'];

    /** @var array<string, mixed> */
    protected $attributes = ['response' => 'pending'];

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
