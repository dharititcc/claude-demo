<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A file-manager document filed against a customer.
 *
 * Deliberately exposes no storage path: the bytes are served through the
 * existing download route, which authorizes the request. Handing out a path
 * would invite the client to construct its own URL to the disk.
 *
 * @mixin File
 */
class CustomerDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'name' => $this->name,
            'category' => $this->category,
            'mime_type' => $this->mime_type,
            'size' => $this->size,

            'version' => $this->version,
            'replaces_id' => $this->replaces_id,
            // True when nothing supersedes this row. Derived from the chain, so
            // it cannot disagree with the data (see File::scopeCurrent).
            'is_current' => $this->replacedBy === null,

            // Whether the browser can show it inline rather than downloading.
            'is_previewable' => $this->isPreviewable(),

            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
