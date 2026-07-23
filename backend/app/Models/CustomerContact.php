<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A person at a customer company.
 *
 * `created_by` refers to the central users table, so it is resolved through
 * `App\Support\CentralUsers` rather than an Eloquent relation — a belongsTo
 * would try to read `users` from the tenant database.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $mobile
 * @property string|null $department
 * @property string|null $job_title
 * @property string|null $notes
 * @property bool $is_primary
 * @property string $status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Customer $customer
 */
class CustomerContact extends Model
{
    use Auditable;
    use SoftDeletes;
    use UsesTenantConnection;

    /**
     * Attributes recorded in the audit trail (see Auditable).
     *
     * @var array<int, string>
     */
    protected array $auditable = ['first_name', 'last_name', 'email', 'job_title', 'is_primary', 'status'];

    /** @var list<string> */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'mobile',
        'department',
        'job_title',
        'notes',
        'status',
        'created_by',
    ];

    /**
     * `is_primary` is deliberately NOT fillable: it is state the application
     * owns, because promoting one contact must demote the others. It is set
     * through CustomerContactService::makePrimary(), never by mass assignment.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'is_primary' => false,
    ];

    /** Statuses a contact may hold. */
    public const STATUSES = ['active', 'inactive'];

    /** Columns the API permits sorting by (allow-list, not user input). */
    public const SORTABLE = ['first_name', 'last_name', 'email', 'job_title', 'status', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Display name, tolerating a contact recorded with only a first name. */
    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    /**
     * Free-text search across the fields someone would actually type.
     *
     * @param Builder<CustomerContact> $query
     * @return Builder<CustomerContact>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        // Escape LIKE wildcards so a literal % or _ does not match everything.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
        $like = "%{$escaped}%";

        return $query->where(function (Builder $q) use ($like) {
            $q->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('job_title', 'like', $like)
                ->orWhere('department', 'like', $like);
        });
    }

    /**
     * Primary first, then alphabetical — the order a person expects to read them.
     *
     * @param Builder<CustomerContact> $query
     * @return Builder<CustomerContact>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_primary')->orderBy('first_name')->orderBy('id');
    }
}
