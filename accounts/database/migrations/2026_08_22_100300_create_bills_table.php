<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bills — §6, §17 step 4.
 *
 * MONEY IS decimal(16,4), NOT decimal(_,2) and never a float. Two reasons:
 * Gross Amount prints at THREE decimals in at least two live places (the Payments
 * split grid and All Pending Approvals, addendum §5), so two is not enough; and
 * the split-equally remainder rule (§6.3) has to be exact to the paisa, which
 * means integer-style arithmetic on fixed-scale decimals, never floats.
 *
 * NO CHECK on status. Both `Payment InProgress` and `Payment Inprogress` are live
 * in Accounts.ds (addendum §10) — constraining to either would reject real rows.
 * The documented set is {Draft, Paid, Partially Paid, Overdue, Payment Inprogress,
 * Overpaid}, compared through one normalising accessor rather than inline.
 *
 * `payable_amount` is deliberately NOT computed here. §6.3 records two different
 * formulas producing different quantities under the same field name, and which is
 * authoritative for Bills is an open [TODO]. Storing it without deciding would
 * bake in a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();

            $table->date('bill_date')->nullable();
            $table->date('due_date')->nullable();
            $table->text('bill_no')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('coa_account_id')->nullable()->constrained('coa_accounts')->nullOnDelete();
            $table->text('ca_email')->nullable();
            $table->boolean('gst_needed')->default(false);

            // Location derives FROM the villas on Bills (§5.1). Schedule_Payment
            // goes the other way and §5.1 flags one of the two as wrong — so this
            // is stored, not trusted as canonical.
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('head_office_id')->nullable()->constrained('head_offices')->nullOnDelete();

            $table->text('status')->nullable();          // verbatim; see docblock
            $table->boolean('split_equally')->default(false);

            $table->decimal('amount', 16, 4)->nullable();          // "Gross Amount"
            $table->decimal('gst_amount', 16, 4)->nullable();
            $table->foreignId('tds_rate_id')->nullable()->constrained('tds_rates')->nullOnDelete();
            $table->decimal('tds_amount', 16, 4)->nullable();
            $table->decimal('invoice_amount', 16, 4)->nullable();
            $table->decimal('paid_amount', 16, 4)->default(0);
            $table->decimal('payable_amount', 16, 4)->nullable();  // formula unresolved
            $table->decimal('adjusted_amount', 16, 4)->nullable();

            $table->string('books_id', 24)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('bill_date');
        });

        // Item_Category and Villas are lists on the form.
        Schema::create('bill_item_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_category_id')->constrained()->cascadeOnDelete();
            $table->unique(['bill_id', 'item_category_id']);
        });

        Schema::create('bill_villa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('villa_id')->constrained()->cascadeOnDelete();
            $table->unique(['bill_id', 'villa_id']);
        });

        Schema::create('bill_billing_cycle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_cycle_id')->constrained()->cascadeOnDelete();
            $table->unique(['bill_id', 'billing_cycle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_billing_cycle');
        Schema::dropIfExists('bill_villa');
        Schema::dropIfExists('bill_item_category');
        Schema::dropIfExists('bills');
    }
};
