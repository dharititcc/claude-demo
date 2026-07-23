<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Note;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer use-cases. Runs inside tenant context, so all writes land in the
 * active organization's database.
 */
class CustomerService
{
    public function __construct(private readonly EventDispatcher $events) {}

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, User $actor): Customer
    {
        $customer = DB::transaction(function () use ($data, $actor) {
            $customer = Customer::create([
                ...$this->attributes($data),
                // Default ownership to whoever created the record.
                'owner_id' => $data['owner_id'] ?? $actor->id,
            ]);

            if (isset($data['tags'])) {
                $this->syncTags($customer, (array) $data['tags']);
            }

            return $customer->load('tags');
        });

        // Fire after the transaction commits: a webhook must never be sent for a
        // customer that a rollback then un-creates.
        $this->events->dispatch('customer.created', ['id' => $customer->id, 'name' => $customer->name]);

        return $customer;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($customer, $data) {
            $customer->update($this->attributes($data));

            // Only touch tags when the caller actually sent them: an absent key
            // means "leave unchanged", an empty array means "remove all".
            if (array_key_exists('tags', $data)) {
                $this->syncTags($customer, (array) $data['tags']);
            }

            return $customer->load('tags');
        });
    }

    public function delete(Customer $customer): void
    {
        $customer->delete(); // soft delete — recoverable
    }

    public function restore(Customer $customer): Customer
    {
        $customer->restore();

        return $customer;
    }

    /**
     * Attach tags by name, creating any that don't exist yet.
     *
     * @param array<int, string> $names
     */
    public function syncTags(Customer $customer, array $names): void
    {
        $ids = collect($names)
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->map(fn (string $name) => Tag::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name],
            )->id)
            ->all();

        $customer->tags()->sync($ids);
    }

    public function addNote(Customer $customer, User $author, string $body): Note
    {
        $note = new Note(['user_id' => $author->id, 'body' => $body]);
        $customer->notes()->save($note);

        return $note;
    }

    /**
     * Stream customers as CSV.
     *
     * Streamed and chunked rather than built in memory: an export of a large
     * organization would otherwise exhaust PHP's memory limit.
     *
     * The streaming callback runs when the response is sent, which is *after*
     * the tenant middleware's terminate() has reverted to the central context.
     * So the tenant is captured and re-entered inside the callback via run() —
     * without it the chunked query would execute against the central database.
     *
     * @param Builder<Customer> $query
     */
    public function exportCsv(Builder $query): StreamedResponse
    {
        $columns = ['id', 'name', 'email', 'phone', 'company', 'website', 'status',
            'city', 'state', 'country', 'lifetime_value', 'created_at'];

        $tenant = tenant();

        return response()->streamDownload(function () use ($query, $columns, $tenant) {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, $columns);

            $stream = function () use ($query, $handle, $columns) {
                $query->chunk(500, function ($customers) use ($handle, $columns) {
                    foreach ($customers as $customer) {
                        fputcsv($handle, array_map(
                            fn (string $column) => (string) $customer->{$column},
                            $columns,
                        ));
                    }
                });
            };

            // Re-establish tenant context for the duration of the stream. In
            // central context (no active tenant) there is nothing to restore.
            $tenant !== null ? $tenant->run($stream) : $stream();

            fclose($handle);
        }, 'customers-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Import customers from an uploaded CSV.
     *
     * Rows are validated individually; a bad row is reported rather than
     * aborting the whole file, since partial imports are the common need.
     *
     * @return array{imported: int, skipped: int, errors: array<int, string>}
     */
    public function importCsv(string $path, User $actor): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Could not read the uploaded file.']];
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0, 'errors' => ['The file is empty.']];
        }

        $header = array_map(fn ($h) => Str::snake(trim((string) $h)), $header);

        if (! in_array('name', $header, true)) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0, 'errors' => ['A "name" column is required.']];
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            // fgetcsv yields [null] for a blank line; it never yields [].
            if ($row === [null]) {
                continue;
            }

            $data = array_combine(
                $header,
                array_pad(array_slice($row, 0, count($header)), count($header), null),
            );

            if (blank($data['name'] ?? null)) {
                $skipped++;
                $errors[] = "Line {$line}: missing name.";

                continue;
            }

            $status = $data['status'] ?? 'lead';

            Customer::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'company' => $data['company'] ?? null,
                'website' => $data['website'] ?? null,
                'status' => in_array($status, Customer::STATUSES, true) ? $status : 'lead',
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'country' => $data['country'] ?? null,
                'owner_id' => $actor->id,
            ]);

            $imported++;
        }

        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 50)];
    }

    /**
     * Whitelist the writable attributes, so tags/owner handling stays explicit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'email', 'phone', 'mobile', 'company', 'trading_name',
            'tax_number', 'registration_number', 'industry', 'website', 'status',
            // Billing address.
            'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
            // Shipping address.
            'shipping_address_line1', 'shipping_address_line2', 'shipping_city',
            'shipping_state', 'shipping_postal_code', 'shipping_country',
            'timezone', 'currency', 'logo_path',
            'lifetime_value', 'owner_id',
        ]));
    }
}
