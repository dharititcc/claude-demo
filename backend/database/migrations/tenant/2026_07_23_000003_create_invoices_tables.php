<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices an organization issues TO ITS CUSTOMERS.
 *
 * Deliberately unrelated to the Billing module, which is Cashier/Stripe and
 * records what the organization owes *us* for the SaaS. Different party,
 * different money, different lifecycle — sharing a table would conflate the two.
 *
 * Only settled facts are stored as status: draft, sent, paid, void. "Overdue"
 * and "partially paid" are derived from due_date and amount_paid at read time,
 * because a stored status would silently go stale the moment a due date passes
 * with nobody writing to the row.
 *
 * Money is decimal, matching customers.lifetime_value and projects.budget.
 * Totals are computed in integer minor units before being stored (see
 * InvoiceService) so repeated float arithmetic cannot drift a balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // An invoice is a legal record of what a customer owes: it must not
            // outlive its customer silently, and it must not be re-pointed.
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Issued by the application, unique within the organization.
            $table->string('number', 32);

            $table->string('status')->default('draft'); // draft|sent|paid|void

            $table->date('issue_date');
            $table->date('due_date');
            $table->timestamp('paid_at')->nullable();

            $table->string('currency', 3)->default('USD');

            // Denormalised from the line items on every write, so listing and
            // reporting never has to sum children.
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->unsignedBigInteger('created_by')->nullable(); // central users.id

            $table->timestamps();
            $table->softDeletes();

            $table->unique('number');
            $table->index('status');
            $table->index('due_date');
            // The customer detail tab: this customer's invoices, newest first.
            $table->index(['customer_id', 'issue_date']);
            // The overdue sweep: unpaid invoices past their due date.
            $table->index(['status', 'due_date']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->string('description');
            // Fractional quantities are normal — 1.5 hours, 0.25 days.
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            // Percentage, per line: jurisdictions tax different goods differently.
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);

            // Explicit ordering: an invoice's lines have a meaningful sequence
            // that insertion id would only accidentally preserve.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['invoice_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
