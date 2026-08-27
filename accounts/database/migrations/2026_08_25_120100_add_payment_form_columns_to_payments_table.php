<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Payment form's remaining fields — for the DIRECT payment entry path.
 *
 * WHY THIS EXISTS NOW. `payments` was built to serve §7.2's `Create_Payment`, which
 * copies a fixed set of values off a bill. Husain corrected the model on
 * 25-Aug-2026: **a payment can also be entered directly**, not only from a bill. A
 * direct form needs the fields a human actually fills, and the schema was missing
 * most of them.
 *
 * THE FIELD LIST IS NOT GUESSED. It comes from the `Payment` form in
 * `deluge/Accounts.ds`, lines 7273-8673 — 130 entries across 10 sections, each
 * carrying `row` and `column`, which is what gives the form its layout. Same route
 * used for the Villa form. Only scalar fields are added here; the three subform
 * GRIDS (`Bill Payments`, `Bills`, `Split Payments`) already have their own tables
 * or are deliberately deferred.
 *
 * ---------------------------------------------------------------------------
 * TWO FILTERS ON THAT FORM CORRECTED CODE ALREADY SHIPPED, recorded here because
 * they are facts about the domain, not about this migration:
 *
 *  1. `COA -> COA[Hide == true].ID`. The Payment form's COA picker shows accounts
 *     where `Hide` is TRUE — 47 of 144. Counter-intuitive enough to look like a bug,
 *     but the 47 are the real entity/bank accounts (`EKOSTAY IDFC LLP`, `Staff
 *     Loan`, `Co founder Personal`) while the 97 with `Hide = false` are per-owner
 *     withholding ledgers. So `Hide` does not mean hidden; on this form it means
 *     selectable. This answers the open COA `hide` question in addendum §17.5.
 *
 *  2. `Vendor_Name -> Vendor_Master[Main_Primary.Main_Primary is not null].ID` —
 *     6,957 of 8,064, which excludes the customer payees rather than the merged-away
 *     vendors. See VendorController::lookup().
 *
 * ---------------------------------------------------------------------------
 * STATUTORY DEDUCTIONS ARE MONEY, so `decimal(16,4)` like everything else — PT,
 * ESIC and PF sit beside TDS on the form's Commercials section. No floats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // --- Section 1, the identity and routing half of the form ---
            $table->text('payment_mode')->nullable();          // picklist: Online / Offline
            $table->text('payment_source')->nullable();
            $table->foreignId('bank_coa_account_id')->nullable()
                ->constrained('coa_accounts')->nullOnDelete();  // Bank Name -> COA[Bank == true]
            $table->text('payment_by')->nullable();
            $table->text('management_remarks')->nullable();
            $table->text('particulars')->nullable();
            $table->text('ca_email')->nullable();
            $table->decimal('original_amount', 16, 4)->nullable();

            $table->boolean('verified')->default(false);
            $table->boolean('marked_as_paid')->default(false);
            $table->boolean('multiple_villa')->default(false);
            $table->boolean('split_equally')->default(false);
            $table->boolean('gst_needed')->default(false);
            $table->boolean('bill_pay_full')->default(false);

            // --- Billing section. Year and Months are SEPARATE from the cycle list:
            // §6.4 records month derivation as the place a junk cycle got created, so
            // these are stored as entered and never used to mint a cycle.
            $table->integer('billing_year')->nullable();
            $table->text('billing_months')->nullable();        // multi-select, comma-packed
            $table->integer('next_total_months')->nullable();

            // --- Commercials: the statutory deductions beside TDS ---
            $table->decimal('pt_amount', 16, 4)->nullable();
            $table->decimal('esic_amount', 16, 4)->nullable();
            $table->decimal('pf_amount', 16, 4)->nullable();

            // GST_Type picklist is `Predefined GST` / `Enter Manully` — the
            // misspelling is Creator's and is preserved (handoff §2 rule 7).
            $table->text('gst_type')->nullable();
            $table->foreignId('tax_id')->nullable()->constrained('taxes')->nullOnDelete();

            // --- Haewaya / external reconciliation ---
            $table->text('haewaya_utr_number')->nullable();
            $table->text('haewaya_id')->nullable();
            $table->text('haewaya_timestamp')->nullable();     // TEXT: it is a text field
            $table->boolean('bank_reconciliation')->default(false);

            // --- Totals the form carries alongside the split grid ---
            $table->boolean('total_bill_amount_flag')->default(false);
            $table->decimal('bill_total_amount', 16, 4)->nullable();
            $table->boolean('check_total_split_amount')->default(false);
            $table->decimal('total_split_amount', 16, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_coa_account_id');
            $table->dropConstrainedForeignId('tax_id');
            $table->dropColumn([
                'payment_mode', 'payment_source', 'payment_by', 'management_remarks',
                'particulars', 'ca_email', 'original_amount',
                'verified', 'marked_as_paid', 'multiple_villa', 'split_equally',
                'gst_needed', 'bill_pay_full',
                'billing_year', 'billing_months', 'next_total_months',
                'pt_amount', 'esic_amount', 'pf_amount', 'gst_type',
                'haewaya_utr_number', 'haewaya_id', 'haewaya_timestamp',
                'bank_reconciliation',
                'total_bill_amount_flag', 'bill_total_amount',
                'check_total_split_amount', 'total_split_amount',
            ]);
        });
    }
};
