<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Issued by the application, never client-supplied.
            'customer_number' => $this->customer_number,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'company' => $this->company,
            'trading_name' => $this->trading_name,
            'tax_number' => $this->tax_number,
            'registration_number' => $this->registration_number,
            'industry' => $this->industry,
            'website' => $this->website,
            'status' => $this->status,

            // The pre-existing address_* columns are the billing address; the
            // key stays `address` so existing clients keep working.
            'address' => [
                'line1' => $this->address_line1,
                'line2' => $this->address_line2,
                'city' => $this->city,
                'state' => $this->state,
                'postal_code' => $this->postal_code,
                'country' => $this->country,
            ],

            'shipping_address' => [
                'line1' => $this->shipping_address_line1,
                'line2' => $this->shipping_address_line2,
                'city' => $this->shipping_city,
                'state' => $this->shipping_state,
                'postal_code' => $this->shipping_postal_code,
                'country' => $this->shipping_country,
            ],

            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'logo_path' => $this->logo_path,

            'lifetime_value' => (float) $this->lifetime_value,
            'owner_id' => $this->owner_id,

            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'notes' => NoteResource::collection($this->whenLoaded('notes')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'contacts' => CustomerContactResource::collection($this->whenLoaded('contacts')),
            // Only the primary is loaded on list screens — see Customer::primaryContact.
            'primary_contact' => new CustomerContactResource($this->whenLoaded('primaryContact')),
            'notes_count' => $this->whenCounted('notes'),
            'contacts_count' => $this->whenCounted('contacts'),
            'projects_count' => $this->whenCounted('projects'),

            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
