<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EventAttendee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventAttendee
 */
class EventAttendeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'response' => $this->response,
        ];
    }
}
