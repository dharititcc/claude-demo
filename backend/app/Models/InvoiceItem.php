<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of an invoice.
 *
 * `line_total` is stored rather than computed on read: it is the figure the
 * customer was actually shown, and recalculating it later — after a tax rate or
 * rounding rule changes — would silently restate an issued document.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $tax_rate
 * @property string $line_total
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice $invoice
 */
class InvoiceItem extends Model
{
    use UsesTenantConnection;

    /**
     * `line_total` is deliberately not fillable — it is derived from quantity,
     * unit_price and tax_rate by InvoiceService, never sent by a client. A
     * client that could post its own line total could invoice any amount for
     * any quantity.
     *
     * @var list<string>
     */
    protected $fillable = [
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
