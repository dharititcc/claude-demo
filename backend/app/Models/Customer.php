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
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Lives in the active tenant's database, so every query is already scoped to
 * one organization — there is no organization_id column to filter on.
 *
 * @property int $id
 * @property string $name
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

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'company',
        'website',
        'status',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
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
    public const SORTABLE = ['name', 'email', 'company', 'status', 'lifetime_value', 'created_at', 'updated_at'];

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
