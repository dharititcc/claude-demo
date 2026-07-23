<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invoices issued to customers.
 *
 * Two things live here rather than in a controller, because getting either
 * wrong is expensive:
 *
 *  - Money. Every figure is computed in integer minor units and only converted
 *    back at the end. Summing decimals as PHP floats drifts — 0.1 + 0.2 is the
 *    canonical example — and a drifting balance on a financial document is not
 *    a rounding curiosity, it is a dispute with a customer.
 *
 *  - Status. An invoice moves draft → sent → paid, or is voided. The rules about
 *    what may still be edited, and when `paid_at` is stamped, are stated once
 *    here instead of at each call site that touches a status.
 */
class InvoiceService
{
    /** Prefix for generated invoice numbers (INV-000001). */
    private const NUMBER_PREFIX = 'INV-';

    public function __construct(private readonly EventDispatcher $events) {}

    /**
     * @param array<string, mixed> $data
     */
    public function create(Customer $customer, array $data, User $actor): Invoice
    {
        $invoice = DB::transaction(function () use ($customer, $data, $actor) {
            // Built with forceFill and saved once: `number` is NOT NULL and is
            // not fillable, so it has to be part of the same insert rather than
            // a second write.
            $invoice = new Invoice;

            $invoice->forceFill([
                'customer_id' => $customer->getKey(),
                'number' => $this->nextNumber(),
                'created_by' => $actor->id,
                'issue_date' => $data['issue_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? now()->addDays(30)->toDateString(),
                // Fall back to the customer's currency, then the organization's.
                'currency' => strtoupper((string) ($data['currency'] ?? $customer->currency ?? config('cashier.currency', 'usd'))),
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
            ])->save();

            $this->replaceItems($invoice, $data['items'] ?? []);

            return $invoice;
        });

        // After commit: a webhook must never fire for an invoice a rollback
        // then un-creates.
        $this->events->dispatch('invoice.created', [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'customer_id' => $invoice->customer_id,
        ]);

        return $invoice->load('items');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ValidationException
     */
    public function update(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            // Line items may only change while the invoice is a draft. Once it
            // has been sent, the customer holds a copy: restating the figures
            // would make our record disagree with theirs.
            if (array_key_exists('items', $data)) {
                $this->assertEditable($invoice);
                $this->replaceItems($invoice, (array) $data['items']);
            }

            $invoice->fill(array_intersect_key($data, array_flip([
                'issue_date', 'due_date', 'currency', 'notes', 'terms',
            ])));

            if ($invoice->isDirty(['issue_date', 'currency'])) {
                $this->assertEditable($invoice);
            }

            $invoice->save();

            return $invoice->refresh()->load('items');
        });
    }

    /**
     * Issue the invoice to the customer.
     *
     * @throws ValidationException
     */
    public function send(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => __('Only a draft invoice can be sent.'),
            ]);
        }

        if ($invoice->items()->doesntExist()) {
            throw ValidationException::withMessages([
                'items' => __('An invoice needs at least one line before it can be sent.'),
            ]);
        }

        $invoice->forceFill(['status' => 'sent'])->save();

        $this->events->dispatch('invoice.sent', ['id' => $invoice->id, 'number' => $invoice->number]);

        return $invoice->refresh();
    }

    /**
     * Record money received.
     *
     * Accepts part payments; the invoice only becomes `paid` once the balance
     * reaches zero, and `paid_at` is stamped at that moment rather than on the
     * first instalment.
     *
     * @throws ValidationException
     */
    public function recordPayment(Invoice $invoice, float $amount): Invoice
    {
        if ($invoice->status === 'void') {
            throw ValidationException::withMessages([
                'amount' => __('A void invoice cannot take a payment.'),
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('Enter an amount greater than zero.'),
            ]);
        }

        return DB::transaction(function () use ($invoice, $amount) {
            $paidMinor = $this->toMinor((float) $invoice->amount_paid) + $this->toMinor($amount);
            $totalMinor = $this->toMinor((float) $invoice->total);

            if ($paidMinor > $totalMinor) {
                throw ValidationException::withMessages([
                    'amount' => __('That is more than the outstanding balance of :balance.', [
                        'balance' => number_format($invoice->balance(), 2),
                    ]),
                ]);
            }

            $settled = $paidMinor >= $totalMinor;

            $invoice->forceFill([
                'amount_paid' => $this->toMajor($paidMinor),
                'status' => $settled ? 'paid' : ($invoice->status === 'draft' ? 'sent' : $invoice->status),
                // Stamped when the balance clears, not on the first instalment.
                'paid_at' => $settled ? now() : null,
            ])->save();

            if ($settled) {
                $this->events->dispatch('invoice.paid', ['id' => $invoice->id, 'number' => $invoice->number]);
            }

            return $invoice->refresh();
        });
    }

    /**
     * Cancel an invoice that should never have been issued.
     *
     * Voiding rather than deleting: an issued invoice is a numbered financial
     * record, and a gap in the sequence is exactly what an auditor asks about.
     *
     * @throws ValidationException
     */
    public function void(Invoice $invoice): Invoice
    {
        if ($invoice->status === 'paid') {
            throw ValidationException::withMessages([
                'status' => __('A paid invoice cannot be voided. Raise a credit note instead.'),
            ]);
        }

        $invoice->forceFill(['status' => 'void'])->save();

        return $invoice->refresh();
    }

    /**
     * Replace every line, then restate the invoice totals from them.
     *
     * Replace rather than diff: the client sends the whole line set, and
     * matching them up by id would add a way for one invoice's line to be moved
     * onto another.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function replaceItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->delete();

        $subtotalMinor = 0;
        $taxMinor = 0;

        foreach (array_values($items) as $position => $item) {
            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $taxRate = (float) ($item['tax_rate'] ?? 0);

            // Round to minor units once, at the line, so the invoice total is
            // the sum of what each line displays — not a figure that disagrees
            // with its own lines by a cent.
            $lineMinor = (int) round($quantity * $unitPrice * 100);
            $lineTaxMinor = (int) round($lineMinor * $taxRate / 100);

            $subtotalMinor += $lineMinor;
            $taxMinor += $lineTaxMinor;

            // `line_total` is not fillable — a client that could post its own
            // line total could invoice any amount for any quantity — so it is
            // written deliberately here rather than mass assigned.
            $line = $invoice->items()->make([
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'position' => $position,
            ]);

            $line->forceFill(['line_total' => $this->toMajor($lineMinor)])->save();
        }

        $invoice->forceFill([
            'subtotal' => $this->toMajor($subtotalMinor),
            'tax_total' => $this->toMajor($taxMinor),
            'total' => $this->toMajor($subtotalMinor + $taxMinor),
        ])->save();
    }

    /**
     * @throws ValidationException
     */
    private function assertEditable(Invoice $invoice): void
    {
        if (! $invoice->isEditable()) {
            throw ValidationException::withMessages([
                'items' => __('This invoice has been issued and its figures can no longer be changed. Void it and raise a new one.'),
            ]);
        }
    }

    /**
     * The next invoice number for this organization.
     *
     * Taken from the highest already issued, including soft-deleted rows, so a
     * number that has appeared on a document is never reused. The unique index
     * is what ultimately guarantees it.
     */
    private function nextNumber(): string
    {
        $highest = Invoice::withTrashed()
            ->orderByRaw('LENGTH(number) DESC, number DESC')
            ->value('number');

        $next = $highest === null ? 1 : ((int) ltrim((string) preg_replace('/\D/', '', $highest), '0')) + 1;

        return self::NUMBER_PREFIX.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function toMinor(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function toMajor(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
