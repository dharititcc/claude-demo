<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoginHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoginHistory
 */
class LoginHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'successful' => $this->successful,
            'reason' => $this->reason,
            'attempted_at' => $this->attempted_at?->toIso8601String(),
        ];
    }
}
