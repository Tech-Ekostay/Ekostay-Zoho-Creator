<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments — §7. The write path §17 step 7 gated until the four §16 "blocking
 * write paths" questions were answered. All four are now closed against the DS:
 *
 *   §3.3 role->permission matrix   docs/permission_matrix.json, 122 permissions
 *   §7.6 payment-number padding    moot; the counter is at 20938, so every pad
 *                                  branch in Accounts.ds:45400 is dead code
 *   §7.2 partial-paid sign         a BUG, not a convention — see PayableFormula
 *   §12.4 Expenses hard delete     confirmed destructive; not reproduced
 *
 * MONEY IS decimal(16,4) as on Bills, and for the same two reasons: Gross Amount
 * prints at three decimals in the Payments split grid (addendum §5), and the
 * split arithmetic has to stay exact to the paisa.
 *
 * NO CHECK CONSTRAINT ON EITHER STATUS AXIS. §7.3 documents dirty enums that are
 * live: Status carries both "Sent for Approval" and "Send for Approval";
 * Payment_Status carries lowercase "paid"; and Payment_Status = "Open" is
 * confirmed in live data while not being in the declared picklist at all —
 * Create_Payment writes it. Constraining either column would reject real rows.
 * Comparison goes through App\Domain\Payments\PaymentStatus.
 *
 * THE VESTIGIAL FIELDS IN §7.1 ARE NOT REPRODUCED. The A/B/C/D checkboxes, the
 * eight *_Updated_User_* fields, the untouched Radio {Choice 1..3} Creator
 * default, and the duplicate Bill_No/Bill_No1, Location/Multi_Location,
 * Payment_Reference_Number/..._Number1 pairs carry no behaviour anywhere in
 * 59,063 lines of DS. Carrying them forward would import 30-odd columns of noise
 * into the ledger's core table. A deliberate deviation, not an oversight.
 *
 * Paid_Amount IS DELIBERATELY ABSENT. §7.1: it is a CHECKBOX on Payment and a
 * currency field on Bills — same name, different type. A boolean called
 * paid_amount sitting beside real money columns is a defect waiting to happen.
 * The state it encodes is already carried by payment_status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();

            // "EKS/PY/20938". §7.6's whole argument is that anything keyed on
            // payment number drifts when a number is reused after a delete.
            $table->text('payment_no')->nullable();

            $table->foreignId('bill_id')->nullable()->constrained('bills')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('coa_account_id')->nullable()->constrained('coa_accounts')->nullOnDelete();
            $table->foreignId('master_category_id')->nullable()->constrained('master_categories')->nullOnDelete();
            $table->foreignId('item_category_id')->nullable()->constrained('item_categories')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('head_office_id')->nullable()->constrained('head_offices')->nullOnDelete();
            $table->foreignId('tds_rate_id')->nullable()->constrained('tds_rates')->nullOnDelete();

            // Creator's Villa_Name on Payment is single-valued (Accounts.ds:45433
            // assigns input.Villas straight to it) even though Bills.Villas is a
            // list. Attribution lives on the split legs; this is a convenience.
            $table->foreignId('villa_id')->nullable()->constrained('villas')->nullOnDelete();

            $table->text('booking_no')->nullable();
            $table->text('status')->nullable();          // axis 1 — see docblock
            $table->text('payment_status')->nullable();  // axis 2 — see docblock

            // §7.1 lists six amount fields on the Creator form. Four of them are
            // the same quantity under different names; these are the distinct ones.
            $table->decimal('amount', 16, 4)->nullable();          // Gross
            $table->decimal('gst_amount', 16, 4)->nullable();
            $table->decimal('tds_amount', 16, 4)->nullable();
            $table->decimal('total_amount', 16, 4)->nullable();
            $table->decimal('payable_amount', 16, 4)->nullable();

            $table->date('requested_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('backend_payment_date')->nullable();

            $table->text('payment_reference_number')->nullable();  // "Haewaya UTR Number"
            $table->text('remarks')->nullable();

            $table->boolean('accounts_bills')->default(false);
            $table->text('added_user')->nullable();
            $table->text('expense_by')->nullable();

            /*
             * REVERSAL, NOT DELETION — §7.6.
             *
             * Creator ships Delete Paid Payment in a More menu one click from a
             * settled payment, and field notes record 17 real payments destroyed
             * by it. There are also 14 unguarded `delete from Payment` sites in
             * Accounts.ds.
             *
             * Nothing here hard-deletes. A reversal is a NEW row with negative
             * amounts pointing at the original through reverses_payment_id; the
             * original keeps its number and its row. reversal_reason is required
             * by the domain action, never by a NOT NULL column — the column has to
             * stay nullable because forward payments have no reason.
             */
            $table->foreignId('reverses_payment_id')->nullable()
                ->constrained('payments')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamp('reversed_at')->nullable();

            $table->string('books_id', 24)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('payment_no');
            $table->index('status');
            $table->index('payment_status');
            $table->index('payment_date');
            $table->index('reverses_payment_id');
        });

        /*
         * The Bill_Payments subform. One payment can settle several bills, which
         * is why this is a grid and not just the bill_id column above.
         */
        Schema::create('payment_bill_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained('bills')->nullOnDelete();
            $table->decimal('bill_amount', 16, 4)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['payment_id', 'position']);
        });

        /*
         * The Split_Payments subform — the payment-side mirror of
         * bill_split_payments, and per §5.2 what every downstream
         * villa-month-category figure resolves to.
         *
         * NO UNIQUE ON THE COMBINATION TRIPLE, unlike bill_split_payments. A
         * partially-paid bill legitimately produces several payments carrying the
         * same villa x category x cycle leg over time; uniqueness there belongs to
         * the bill, not the payment.
         */
        Schema::create('payment_split_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            $table->foreignId('villa_id')->nullable()->constrained('villas')->nullOnDelete();
            $table->foreignId('item_category_id')->nullable()->constrained('item_categories')->nullOnDelete();
            $table->foreignId('billing_cycle_id')->nullable()->constrained('billing_cycles')->nullOnDelete();

            $table->decimal('amount', 16, 4)->nullable();
            $table->decimal('tds_amount', 16, 4)->nullable();
            $table->decimal('gst_amount', 16, 4)->nullable();
            $table->decimal('total_amount', 16, 4)->nullable();

            // Creator's backend_Amount on this subform is lowercase-b where the
            // Bills one is Backend_Total_Amount (Accounts.ds:45475). Same
            // quantity; the source casing is a typo, normalised here because this
            // is a column name and not a live lookup key.
            $table->decimal('backend_amount', 16, 4)->nullable();
            $table->decimal('backend_tds_amount', 16, 4)->nullable();
            $table->decimal('backend_gst_amount', 16, 4)->nullable();

            $table->decimal('percent', 9, 4)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['payment_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_split_payments');
        Schema::dropIfExists('payment_bill_payments');
        Schema::dropIfExists('payments');
    }
};
