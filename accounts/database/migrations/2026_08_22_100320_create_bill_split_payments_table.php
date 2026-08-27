<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `Split_Payment` grid — §6.2, and the most consequential table in the app.
 *
 * §5 makes this explicit: an Expenses_Bills row IS one Split_Payment leg,
 * materialised. §5.2 adds that Expenses_Bills is the flattened ledger the
 * downstream expense-control tool syncs, so every villa-month-category figure in
 * that tool traces back to a row here. This grid is the only place attribution is
 * decided.
 *
 * One row per villa x billing cycle x item category combination (§5.1), which is
 * why the natural key is that triple.
 *
 * THE BACKEND TRIPLET. addendum §10 settles what it is: the allocation snapshot
 * taken while nothing is paid. `Paid_Amount == 0` re-syncs backend_total_amount to
 * total_amount on every save; once a payment exists the two diverge and §7.2 says
 * the backend figures are the ones read for a partially-paid bill. A baseline, not
 * a parallel calculation.
 *
 * `flagged` and `flag_reason` implement the reconcile rule from §5.1 and §15.1.
 * Creator clears and rebuilds this grid on every scope change, which destroys
 * typed amounts. The rebuild requirement is the opposite: surviving combinations
 * keep their amounts, new ones arrive blank, and a combination that no longer
 * applies is dropped only if empty — if it carries money it is KEPT, flagged, and
 * blocks save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_split_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();

            // The combination triple. Nullable because the §5.1 degradation tiers
            // produce villa-only and villa x cycle rows as well as the full cross
            // product.
            $table->foreignId('villa_id')->nullable()->constrained('villas')->nullOnDelete();
            $table->foreignId('item_category_id')->nullable()->constrained('item_categories')->nullOnDelete();
            $table->foreignId('billing_cycle_id')->nullable()->constrained('billing_cycles')->nullOnDelete();

            $table->decimal('amount', 16, 4)->nullable();
            $table->decimal('tds_amount', 16, 4)->nullable();
            $table->decimal('gst_amount', 16, 4)->nullable();
            $table->decimal('total_amount', 16, 4)->nullable();

            // The pre-payment snapshot — see docblock.
            $table->decimal('backend_tds_amount', 16, 4)->nullable();
            $table->decimal('backend_gst_amount', 16, 4)->nullable();
            $table->decimal('backend_total_amount', 16, 4)->nullable();

            $table->decimal('percent', 9, 4)->nullable();
            $table->boolean('partial_paid')->default(false);

            // Reconcile bookkeeping (§5.1 / §15.1) — not a Creator field.
            $table->boolean('flagged')->default(false);
            $table->text('flag_reason')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['bill_id', 'position']);
            $table->unique(
                ['bill_id', 'villa_id', 'item_category_id', 'billing_cycle_id'],
                'bill_split_combination_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_split_payments');
    }
};
