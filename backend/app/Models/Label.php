<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A task label. Distinct from Tag (which is polymorphic and customer-facing):
 * labels are workflow markers scoped to tasks, e.g. "bug", "blocked".
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Label extends Model
{
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'color'];

    /** @var array<string, mixed> */
    protected $attributes = ['color' => '#64748b'];

    protected static function booted(): void
    {
        static::saving(function (Label $label) {
            if (blank($label->slug)) {
                $label->slug = Str::slug($label->name);
            }
        });
    }

    /**
     * @return BelongsToMany<Task, $this>
     */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class);
    }
}
