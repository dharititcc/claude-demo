<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    /**
     * Authorization is handled by CustomerPolicy via the controller's
     * authorizeResource(), so this only validates shape.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $central = config('tenancy.database.central_connection');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'registration_number' => ['nullable', 'string', 'max:64'],
            'industry' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'url', 'max:255'],
            'status' => ['sometimes', Rule::in(Customer::STATUSES)],

            // Billing address.
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],

            // Shipping address.
            'shipping_address_line1' => ['nullable', 'string', 'max:255'],
            'shipping_address_line2' => ['nullable', 'string', 'max:255'],
            'shipping_city' => ['nullable', 'string', 'max:255'],
            'shipping_state' => ['nullable', 'string', 'max:255'],
            'shipping_postal_code' => ['nullable', 'string', 'max:20'],
            'shipping_country' => ['nullable', 'string', 'size:2'],

            // `timezone` validates against PHP's list, so a bad value cannot
            // reach a date conversion and throw far from here.
            'timezone' => ['nullable', 'string', 'timezone'],
            'currency' => ['nullable', 'string', 'size:3'],

            'lifetime_value' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],

            // Owner must be a member of this organization: membership is enforced
            // against the central pivot, so a user id from another tenant — which
            // merely exists in the central users table — is rejected.
            'owner_id' => ['nullable', 'integer', Rule::exists("{$central}.organization_user", 'user_id')->where('tenant_id', tenant()->id)],

            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
        ];
    }
}
