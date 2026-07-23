<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'number' => $this->number,

            // `status` is what is stored; `display_status` folds in the two
            // derived states (overdue, partial) so every screen labels an
            // invoice the same way without recomputing the rules.
            'status' => $this->status,
            'display_status' => $this->displayStatus(),
            'is_overdue' => $this->isOverdue(),
            'is_editable' => $this->isEditable(),

            // Both are NOT NULL in the schema, so no nullsafe is warranted.
            'issue_date' => $this->issue_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'paid_at' => $this->paid_at?->toIso8601String(),

            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'tax_total' => (float) $this->tax_total,
            'total' => (float) $this->total,
            'amount_paid' => (float) $this->amount_paid,
            'balance' => $this->balance(),

            'notes' => $this->notes,
            'terms' => $this->terms,

            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'customer' => new CustomerResource($this->whenLoaded('customer')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
