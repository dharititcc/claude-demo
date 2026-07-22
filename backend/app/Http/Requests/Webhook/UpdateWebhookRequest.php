<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhook;

use App\Http\Controllers\Api\V1\WebhookController;
use App\Rules\PublicHttpUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWebhookRequest extends FormRequest
{
    /**
     * Authorization is enforced in the controller via $this->authorize()/policies —
     * Form Requests only shape input.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // SSRF guard — see App\Rules\PublicHttpUrl and StoreWebhookRequest.
            'url' => ['sometimes', 'max:2048', new PublicHttpUrl],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', WebhookController::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
