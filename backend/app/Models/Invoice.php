<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An invoice the organization issued to one of its customers.
 *
 * Not to be confused with the Billing module, which reads Stripe invoices for
 * what the organization owes us for the platform.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $number
 * @property string $status
 * @property Carbon $issue_date
 * @property Carbon $due_date
 * @property Carbon|null $paid_at
 * @property string $currency
 * @property string $subtotal
 * @property string $tax_total
 * @property string $total
 * @property string $amount_paid
 * @property string|null $notes
 * @property string|null $terms
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Customer $customer
 * @property-read Collection<int, InvoiceItem> $items
 */
class Invoice extends Model
{
    use Auditable;
    use SoftDeletes;
    use UsesTenantConnection;

    /**
     * Attributes recorded in the audit trail (see Auditable).
     *
     * @var array<int, string>
     */
    protected array $auditable = ['number', 'status', 'total', 'amount_paid', 'due_date'];

    /**
     * `number`, the money columns and `status` are all absent on purpose: they
     * are application-owned. Totals are derived from the line items and status
     * moves through InvoiceService, so neither may be posted by a client.
     *
     * @var list<string>
     */
    protected $fillable = [
        'issue_date',
        'due_date',
        'currency',
        'notes',
        'terms',
    ];

    /**
     * Mirrors the column defaults in PHP.
     *
     * Without these, a freshly built instance has a null status and null money
     * until it is re-read: the database default applies on insert but is not
     * loaded back, so displayStatus() and balance() would see nothing.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'currency' => 'USD',
        'subtotal' => 0,
        'tax_total' => 0,
        'total' => 0,
        'amount_paid' => 0,
    ];

    /**
     * Statuses actually stored.
     *
     * `overdue` and `partial` are NOT here: both are functions of the clock and
     * the amount paid, so storing them would leave rows describing a state that
     * silently stopped being true. See isOverdue()/isPartiallyPaid().
     */
    public const STATUSES = ['draft', 'sent', 'paid', 'void'];

    /** Columns the API permits sorting by (allow-list, not user input). */
    public const SORTABLE = ['number', 'issue_date', 'due_date', 'total', 'status', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position')->orderBy('id');
    }

    /** What is still owed. Never negative — an overpayment is not a credit. */
    public function balance(): float
    {
        return max(0, round((float) $this->total - (float) $this->amount_paid, 2));
    }

    /**
     * Past its due date with money still owed.
     *
     * Derived rather than stored: an invoice becomes overdue because a date
     * passed, which no write to this row would otherwise record.
     */
    public function isOverdue(): bool
    {
        return $this->status === 'sent'
            && $this->balance() > 0
            && $this->due_date->endOfDay()->isPast();
    }

    public function isPartiallyPaid(): bool
    {
        return (float) $this->amount_paid > 0 && $this->balance() > 0;
    }

    /**
     * The status a human should see, including the two derived ones.
     *
     * The UI shows this; the database keeps `status`. Keeping the two separate
     * is what stops a stored value going stale.
     */
    public function displayStatus(): string
    {
        if ($this->isOverdue()) {
            return 'overdue';
        }

        if ($this->status === 'sent' && $this->isPartiallyPaid()) {
            return 'partial';
        }

        return $this->status;
    }

    /**
     * A draft has not been issued to anybody yet, so it is the only state where
     * the figures may still be edited freely.
     */
    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * @param Builder<Invoice> $query
     * @return Builder<Invoice>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        // Escape LIKE wildcards so a literal % or _ does not match everything.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
        $like = "%{$escaped}%";

        return $query->where(fn (Builder $q) => $q->where('number', 'like', $like)->orWhere('notes', 'like', $like));
    }

    /**
     * Unpaid and past due. Expressed in SQL rather than filtered in PHP so the
     * (status, due_date) index does the work and pagination stays correct.
     *
     * @param Builder<Invoice> $query
     * @return Builder<Invoice>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'sent')
            ->whereDate('due_date', '<', now())
            ->whereColumn('amount_paid', '<', 'total');
    }
}
