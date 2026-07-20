<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Organization-scoped label. Polymorphic so Projects and Tasks can reuse it.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Tag extends Model
{
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'color'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'color' => '#6366f1',
    ];

    protected static function booted(): void
    {
        // Derive the slug so callers only ever supply a display name.
        static::saving(function (Tag $tag) {
            if (blank($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    /**
     * @return MorphToMany<Customer, $this>
     */
    public function customers(): MorphToMany
    {
        return $this->morphedByMany(Customer::class, 'taggable');
    }
}
