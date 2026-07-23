<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Lives in the active tenant's database, so every query is already scoped to
 * one organization — there is no organization_id column to filter on.
 *
 * @property int $id
 * @property string|null $customer_number
 * @property string $name
 * @property string|null $trading_name
 * @property string|null $tax_number
 * @property string|null $registration_number
 * @property string|null $industry
 * @property string|null $mobile
 * @property string|null $shipping_address_line1
 * @property string|null $shipping_address_line2
 * @property string|null $shipping_city
 * @property string|null $shipping_state
 * @property string|null $shipping_postal_code
 * @property string|null $shipping_country
 * @property string|null $timezone
 * @property string|null $currency
 * @property string|null $logo_path
 * @property-read Collection<int, CustomerContact> $contacts
 * @property-read CustomerContact|null $primaryContact
 * @property-read Collection<int, Project> $projects
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $company
 * @property string|null $website
 * @property string $status
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string|null $country
 * @property string $lifetime_value
 * @property int|null $owner_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Tag> $tags
 * @property-read Collection<int, Note> $notes
 * @property-read Collection<int, Attachment> $attachments
 */
class Customer extends Model
{
    use Auditable;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use SoftDeletes;
    use UsesTenantConnection;

    /**
     * Attributes recorded in the audit trail (see Auditable).
     *
     * @var array<int, string>
     */
    protected array $auditable = ['name', 'email', 'phone', 'company', 'status', 'lifetime_value', 'owner_id'];

    /**
     * `customer_number` is deliberately NOT fillable: it is an identifier the
     * application issues, not something a client may choose or overwrite. It is
     * assigned once in CustomerService::create().
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'mobile',
        'company',
        'trading_name',
        'tax_number',
        'registration_number',
        'industry',
        'website',
        'status',
        // Billing address.
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        // Shipping address.
        'shipping_address_line1',
        'shipping_address_line2',
        'shipping_city',
        'shipping_state',
        'shipping_postal_code',
        'shipping_country',
        'timezone',
        'currency',
        'logo_path',
        'lifetime_value',
        'owner_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'lead',
        'lifetime_value' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lifetime_value' => 'decimal:2',
        ];
    }

    /** Statuses a customer may hold. */
    public const STATUSES = ['lead', 'active', 'inactive', 'churned'];

    /** Columns the API permits sorting by (allow-list, not user input). */
    public const SORTABLE = ['name', 'customer_number', 'email', 'company', 'industry', 'status', 'lifetime_value', 'created_at', 'updated_at'];

    /**
     * People at this company.
     *
     * @return HasMany<CustomerContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    /**
     * The one contact marked primary, if any.
     *
     * A HasOne rather than a filter on the loaded collection, so a list screen
     * can eager-load just the primary contact instead of every contact of every
     * customer.
     *
     * @return HasOne<CustomerContact, $this>
     */
    public function primaryContact(): HasOne
    {
        return $this->hasOne(CustomerContact::class)->where('is_primary', true);
    }

    /**
     * Work done for this customer.
     *
     * The projects.customer_id foreign key already existed; this is the inverse
     * of it, so the customer screen reuses the Projects module rather than
     * restating any of it.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    /**
     * @return MorphMany<Note, $this>
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Free-text search across the fields a user would actually type.
     *
     * @param Builder<Customer> $query
     * @return Builder<Customer>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        // Escape LIKE wildcards so a literal % or _ doesn't match everything.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
        $like = "%{$escaped}%";

        return $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('company', 'like', $like)
                ->orWhere('phone', 'like', $like);
        });
    }
}
