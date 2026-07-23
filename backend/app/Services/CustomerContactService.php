<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Contacts belonging to a customer.
 *
 * The whole reason this is a service rather than controller code is the primary
 * invariant: at most one contact per customer may be primary, and promoting one
 * has to demote the other in the same breath. That is a two-statement write, so
 * it belongs in one transactional place instead of being repeated at every call
 * site that can set the flag (create, update, delete, restore).
 */
class CustomerContactService
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(Customer $customer, array $data, User $actor): CustomerContact
    {
        return DB::transaction(function () use ($customer, $data, $actor) {
            // `is_primary` is not fillable — see the model — so it is read out
            // and applied deliberately below.
            $wantsPrimary = (bool) ($data['is_primary'] ?? false);

            $contact = $customer->contacts()->create(
                array_merge($this->attributes($data), ['created_by' => $actor->id]),
            );

            // The first contact is the primary one by default: a company with
            // exactly one contact and no primary is a pointless state to allow.
            if ($wantsPrimary || $customer->contacts()->count() === 1) {
                $this->promote($customer, $contact);
            }

            return $contact->refresh();
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(CustomerContact $contact, array $data): CustomerContact
    {
        return DB::transaction(function () use ($contact, $data) {
            $contact->fill($this->attributes($data))->save();

            if (array_key_exists('is_primary', $data)) {
                if ((bool) $data['is_primary']) {
                    $this->promote($contact->customer, $contact);
                } elseif ($contact->is_primary) {
                    // Demoting the primary directly would leave the customer
                    // with none; make the next contact primary instead.
                    $this->demote($contact);
                }
            }

            return $contact->refresh();
        });
    }

    public function delete(CustomerContact $contact): void
    {
        DB::transaction(function () use ($contact) {
            $wasPrimary = $contact->is_primary;
            $customer = $contact->customer;

            $contact->delete();

            // Never leave a customer with contacts but no primary.
            if ($wasPrimary) {
                $this->promoteFallback($customer);
            }
        });
    }

    public function restore(CustomerContact $contact): CustomerContact
    {
        return DB::transaction(function () use ($contact) {
            $contact->restore();

            // A restored contact does not reclaim primary — someone else may
            // hold it now, and silently swapping it would be surprising.
            if ($contact->customer->contacts()->where('is_primary', true)->doesntExist()) {
                $this->promote($contact->customer, $contact);
            }

            return $contact->refresh();
        });
    }

    /**
     * Make one contact primary and demote whoever held it.
     *
     * Runs as two statements inside the caller's transaction: the demote is
     * scoped to the customer, so it cannot touch another customer's contacts.
     */
    public function promote(Customer $customer, CustomerContact $contact): void
    {
        DB::transaction(function () use ($customer, $contact) {
            $customer->contacts()
                ->whereKeyNot($contact->getKey())
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            $contact->forceFill(['is_primary' => true])->save();
        });
    }

    /** Hand primary to the next contact in line, if there is one. */
    private function demote(CustomerContact $contact): void
    {
        $contact->forceFill(['is_primary' => false])->save();

        $this->promoteFallback($contact->customer);
    }

    private function promoteFallback(Customer $customer): void
    {
        $next = $customer->contacts()->ordered()->first();

        if ($next !== null) {
            $next->forceFill(['is_primary' => true])->save();
        }
    }

    /**
     * Only the attributes a client may set, so an unexpected key in the payload
     * cannot reach the model even if validation is ever loosened.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'first_name',
            'last_name',
            'email',
            'phone',
            'mobile',
            'department',
            'job_title',
            'notes',
            'status',
        ]));
    }
}
