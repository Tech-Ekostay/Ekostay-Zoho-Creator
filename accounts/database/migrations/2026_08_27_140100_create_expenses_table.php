<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `Expenses` — the ledger. §5.2: "an Expenses_Bills row IS one ledger entry."
 *
 * WHY THIS IS ITS OWN TABLE rather than a view over `bill_split_payments`. The
 * All Expenses report (screenshots, 27-Aug-2026) shows 66,407 rows carrying fields
 * that exist on no bill and no payment: `Recon Expense`, `Duplicate`, `Bill
 * Available`, `Link`, the A/B/C/D approval flags with their own updated-user pairs,
 * `Old Billing Cycles`, and a per-record `Update Expense` action. An expense is a
 * record in its own right, not a projection.
 *
 * It is also the only place both origins meet: 56% of ledger rows carry a payment
 * and NO bill, 44% carry both. Measured on 65,305 keyable rows — so a table hanging
 * off bills could not hold the majority of them.
 *
 * ---------------------------------------------------------------------------
 * COLUMN ORDER IS VERIFIED, which is rare here. The 34 columns below are in the
 * order the live report displays them, read across twelve screenshots covering the
 * full horizontal scroll. Not inferred from a form, not guessed from the export's
 * key order — seen.
 *
 * THREE THINGS FROM THOSE SCREENSHOTS THAT WOULD HAVE BEEN GUESSED WRONG:
 *
 *  1. `ID BIlls` — capital I in "BIlls". A live misspelling, and it goes on the
 *     preserve-spellings list. Stored as `id_bills`; the LABEL keeps the typo.
 *  2. `TDS %` is a column of its own, beside `TDS Amount`. Two columns, not one.
 *  3. `Update Expense` is a per-record action rendered as a button INSIDE a column,
 *     second from the left. Not a strip under the grid, which is how Bills' Create
 *     Payment was built. Different shape, and the report puts the action early.
 *
 * ---------------------------------------------------------------------------
 * THE AMOUNT COLUMNS ARE NOT INTERCHANGEABLE. The report shows `Gross Amount`,
 * `TDS Amount`, `GST Amount` and `Amount` side by side, and the detail view adds
 * `Net Paid Amount`, `PT`, `ESIC` and `PF`. From the screenshots:
 *
 *     Gross 58,614.14   TDS 586.14   GST 0.00   Amount 58,028.00
 *
 * so `Amount` is Gross - TDS + GST — the net attributable figure, and the one §5.2
 * means when it says the row is a ledger entry. `Gross Amount` is what the vendor
 * billed. Summing the wrong one misstates the ledger, which is why both are stored
 * rather than one being derived.
 *
 * The export ALSO carries a `New_Gross/New_GST/New_TDS` triplet, identical on 64,699
 * rows and differing on 606. That is a revision mechanism; both sets are kept because
 * which is authoritative when they differ is unestablished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            // 18-digit Creator id, STRING. The only stable natural key (§7), and a
            // float cast corrupts it (…361075 -> …361100).
            $table->string('creator_id', 20)->nullable()->unique();

            // --- the report's column order starts here ---
            $table->timestampTz('added_time')->nullable();          // 1
            // 2 is `Update Expense`, an action, not stored.
            $table->foreignId('primary_villa_id')->nullable()
                ->constrained('villas')->nullOnDelete();            // 3
            $table->text('payment_no')->nullable();                 // 4  the payment's NUMBER
            $table->foreignId('payment_id')->nullable()
                ->constrained('payments')->nullOnDelete();
            $table->text('bill_no')->nullable();                    // 5
            $table->foreignId('bill_id')->nullable()
                ->constrained('bills')->nullOnDelete();

            // Two vendor columns, and they differ: `Primary Vendor Name` is the
            // merge target, `Vendor Name` the record as filed. The screenshots show
            // them agreeing on most rows and the primary blank on a customer payee
            // (`AKHIL(Customer)`), which matches §13A.1's two populations exactly.
            $table->text('primary_vendor_name')->nullable();        // 6
            $table->text('vendor_name')->nullable();                // 7
            $table->foreignId('vendor_id')->nullable()
                ->constrained('vendors')->nullOnDelete();

            $table->date('payment_date')->nullable();               // 8
            $table->date('bill_date')->nullable();                  // 9

            $table->foreignId('villa_id')->nullable()
                ->constrained('villas')->nullOnDelete();            // 10
            $table->text('particulars')->nullable();                // 11
            $table->foreignId('location_id')->nullable()
                ->constrained('locations')->nullOnDelete();         // 12

            $table->decimal('gross_amount', 16, 4)->nullable();     // 13
            $table->decimal('tds_amount', 16, 4)->nullable();       // 14
            // A PERCENTAGE, not money. Its own column beside the amount.
            $table->decimal('tds_percentage', 9, 4)->nullable();    // 15
            $table->decimal('gst_amount', 16, 4)->nullable();       // 16
            // The net attributable figure — see the docblock. NOT the same as gross.
            $table->decimal('amount', 16, 4)->nullable();           // 17

            $table->foreignId('item_category_id')->nullable()
                ->constrained('item_categories')->nullOnDelete();   // 18
            $table->foreignId('master_category_id')->nullable()
                ->constrained('master_categories')->nullOnDelete(); // 19
            $table->foreignId('billing_cycle_id')->nullable()
                ->constrained('billing_cycles')->nullOnDelete();    // 20
            $table->foreignId('coa_account_id')->nullable()
                ->constrained('coa_accounts')->nullOnDelete();      // 21
            $table->foreignId('bank_coa_account_id')->nullable()
                ->constrained('coa_accounts')->nullOnDelete();      // 22  `Bank Name`

            $table->boolean('recon_expense')->default(false);       // 23
            $table->text('vendor_gst_no')->nullable();              // 24
            // Verbatim. `Paid` dominates; blanks are real.
            $table->text('status')->nullable();                     // 25
            // `ID BIlls` — Creator's capital I. Column name normalised, LABEL is not.
            $table->text('id_bills')->nullable();                   // 26
            $table->text('link')->nullable();                       // 27
            // 28 is `ID`, held as creator_id above.
            $table->text('expense_by')->nullable();                 // 29
            // Comma-packed S3 URLs, several per row. A parse, not a split(',').
            $table->text('bills')->nullable();                      // 30
            $table->text('added_user')->nullable();                 // 31
            $table->text('modified_user')->nullable();              // 32
            $table->timestampTz('modified_time')->nullable();       // 33
            $table->text('payment_status')->nullable();             // 34

            // --- on the detail view but not the report ---
            $table->foreignId('head_office_id')->nullable()
                ->constrained('head_offices')->nullOnDelete();
            $table->text('accounts_remarks')->nullable();
            $table->text('management_remarks')->nullable();
            $table->text('payment_by')->nullable();
            $table->decimal('net_paid_amount', 16, 4)->nullable();
            $table->decimal('pt_amount', 16, 4)->nullable();
            $table->decimal('esic_amount', 16, 4)->nullable();
            $table->decimal('pf_amount', 16, 4)->nullable();
            $table->text('payment_reference_number')->nullable();
            $table->text('type')->nullable();                       // Expense / Bill
            $table->text('booking_no')->nullable();
            $table->date('due_date')->nullable();
            $table->text('books_id')->nullable();
            $table->text('ca_email')->nullable();
            $table->boolean('bill_available')->default(false);
            $table->boolean('duplicate')->default(false);
            $table->timestampTz('timestamp_date')->nullable();

            /*
             * THE A/B/C/D FLAGS. Four booleans, each with its own updated-user name
             * and login — twelve columns for what the detail view shows as a block.
             * The DS carries them on the Payment form too. What they MEAN is not
             * established: they are not in any spec section, and A-D as field names
             * says nothing. Stored so the data survives; not interpreted.
             */
            foreach (['a', 'b', 'c', 'd'] as $flag) {
                $table->boolean($flag.'_flag')->default(false);
                $table->text($flag.'_updated_user_name')->nullable();
                $table->text($flag.'_updated_user_login')->nullable();
            }

            $table->text('last_updated_by')->nullable();
            $table->boolean('updated_by_widget')->default(false);
            // Comma-packed, and the name says it: a history of reassignments.
            $table->text('old_billing_cycles')->nullable();

            // The revision triplet — identical on 64,699 rows, differing on 606.
            $table->decimal('new_gross_amount', 16, 4)->nullable();
            $table->decimal('new_gst_amount', 16, 4)->nullable();
            $table->decimal('new_tds_amount', 16, 4)->nullable();

            $table->timestamps();

            // The report sorts by Added Time descending, and filters land on these.
            $table->index('added_time');
            $table->index('payment_date');
            $table->index('status');
            $table->index('bill_no');
            $table->index('payment_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
