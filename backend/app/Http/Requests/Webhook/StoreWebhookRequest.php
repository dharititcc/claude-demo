<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhook;

use App\Http\Controllers\Api\V1\WebhookController;
use App\Rules\PublicHttpUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreWebhookRequest extends FormRequest
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
            // Blocks internal/reserved hosts and non-http schemes (SSRF). Delivery
            // adds the runtime IP re-check; see App\Rules\PublicHttpUrl.
            'url' => ['required', 'max:2048', new PublicHttpUrl],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', WebhookController::EVENTS)],
        ];
    }
}
