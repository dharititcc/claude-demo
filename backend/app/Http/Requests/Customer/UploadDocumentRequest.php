<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Models\File;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadDocumentRequest extends FormRequest
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
     * The extension deny-list and the storage quota are enforced in
     * FileManagerService, not here: they apply to every upload path, and
     * restating them per request is how the two drift apart.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // 25 MB, matching the file manager's own ceiling.
            'file' => ['required', 'file', 'max:25600'],
            'category' => ['nullable', Rule::in(File::CATEGORIES)],
        ];
    }
}
