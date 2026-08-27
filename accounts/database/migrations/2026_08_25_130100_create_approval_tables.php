<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The approval engine's tables — §8.2, and the missing middle of the lifecycle.
 *
 * WHY NOW. §17 said "do not implement the approval engine in the first pass", so it
 * was deferred. The consequence, once real data landed, is that a payment is created
 * and then frozen: there are eight write routes and not one of them is a status
 * transition. `Draft -> Submit for Approval -> Approved -> Paid` has no path through
 * it at all. That deferral is now the thing blocking every downstream step, so the
 * gate is lifted deliberately rather than by drift.
 *
 * THREE TABLES, because Creator uses three records (Accounts.ds 16050-16200):
 *
 *     Approval          the RULE  — which payments it governs, and the level pairing
 *     Approval_Levels   the GRID  — per level: amount band, approvers, Any/All
 *     Pending_Approval  the STATE — one in-flight approval, at one level
 *
 * ---------------------------------------------------------------------------
 * THE ROUTING ALGORITHM, transcribed from Accounts.ds:16054-16112 so the intent
 * survives in one place:
 *
 *   1. amount = Invoice_Amount, or if that is 0, the sum of Bill_Payments.Bill_Amount
 *   2. targetLevel = the level with the GREATEST Minimum_Amount that is still <= amount
 *   3. expand targetLevel into the chain of levels to actually visit:
 *        Level 1 -> [L1]
 *        Level 2 -> lvl12 == "ALL"  ? [L1, L2]     : [L2]
 *        Level 3 -> lvl23 == "ANY"  ? [L3]
 *                                   : (lvl12 == "ALL" ? [L1, L2, L3] : [L2, L3])
 *
 * TWO THINGS ABOUT THAT WORTH RECORDING RATHER THAN TIDYING:
 *
 *  - **`Maximum_Amount` is never read.** The grid captures it, the form validates it
 *    (`row.Minimum_Amount = previous Maximum_Amount + 1`), and the routing ignores it
 *    entirely — only minimums decide. So a band's upper bound is documentation, not
 *    a constraint. The column is kept because the form writes it.
 *  - **The two level pairings are tested in OPPOSITE directions.** Level 2 asks
 *    `lvl12 == "ALL"`; Level 3 asks `lvl23 == "ANY"`. Reproduced as-is. Normalising
 *    them to one sense would change which approvers a payment visits.
 *
 * AND THE CASE THAT LOOKS LIKE A BUG BUT IS A FEATURE: if every band's minimum
 * exceeds the amount, `targetLevel` stays empty and the chain is EMPTY — no approval
 * required. The live data agrees: `Approval Not Required` is a real status, present
 * on exactly one payment. Since `ifnull(Minimum_Amount, 0)` makes an unset minimum 0,
 * a Level 1 with no minimum always matches, which is why this is rare.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * THE RULE. Scoped by module, location, villa and item category — all
         * multi-valued in Creator and exported comma-packed, so they are stored as
         * text and parsed. Splitting is a parse, not a `split(',')`: the packed
         * strings carry leading spaces (addendum §3).
         */
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();

            // Picklist of exactly one value, {"Payment"} — and blank on 1 of the 9
            // live rules, which is why it is nullable.
            $table->text('module')->nullable();

            $table->text('locations')->nullable();
            $table->text('villa_names')->nullable();
            $table->text('item_categories')->nullable();
            $table->text('exclude_categories')->nullable();

            // radiobuttons {"Include","Exclude"} — how item_categories is applied.
            $table->text('scope_type')->nullable();

            // Both picklists are {"Any","All"} and both are NULL on 4 of the 9 rules.
            // Null is meaningful: the DS reads it through ifnull(...,"") for the
            // Level-2 test and ifnull(...,"ALL") elsewhere, so it is preserved
            // rather than defaulted here.
            $table->text('level_1_2_approval')->nullable();
            $table->text('level_2_3_approval')->nullable();

            $table->timestamps();
        });

        /*
         * THE GRID. One row per level per rule.
         *
         * `minimum_amount` IS NULLABLE AND THAT IS THE POINT. The
         * `All_Approvals` export carries only the rule headers — its `Approvers`
         * column is the string "Level 1,Level 2", naming which levels exist and
         * nothing else. So the bands and the approver identities are NOT in any
         * export we hold. Rows are seeded with the levels that exist and null
         * amounts, and the router REFUSES to route rather than guessing a band.
         * A null here means "not known", never "zero".
         */
        Schema::create('approval_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_id')->constrained('approvals')->cascadeOnDelete();

            // "Level 1" / "Level 2" / "Level 3", verbatim — they are compared as
            // strings against Pending_Approval.Approval_Level.
            $table->text('level');

            $table->decimal('minimum_amount', 16, 4)->nullable();
            // Captured because the form writes it; never read when routing.
            $table->decimal('maximum_amount', 16, 4)->nullable();

            // {"Any","All"} — whether one approver at this level suffices.
            $table->text('approval_type')->nullable();

            $table->integer('position')->default(0);
            $table->timestamps();

            $table->unique(['approval_id', 'level']);
        });

        /** Which employees approve at a level. Empty until the grid is exported. */
        Schema::create('approval_level_approver', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_level_id')->constrained('approval_levels')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['approval_level_id', 'employee_id']);
        });

        /*
         * THE IN-FLIGHT STATE. One row per payment being approved, carrying the
         * level it is currently sitting at.
         *
         * `chain` stores the expanded level list as JSON at submit time — a
         * deliberate snapshot. §14's lesson applies: a rule that changes mid-flight
         * must not silently re-decide an approval already in progress, so the chain
         * this payment will actually walk is frozen when it is submitted.
         */
        Schema::create('pending_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();

            $table->text('approval_level')->nullable();      // where it sits now
            $table->json('chain')->nullable();               // the frozen level list
            $table->text('status')->nullable();              // mirrors Payment.Status
            $table->boolean('next_level_approval_required')->default(true);

            $table->decimal('approval_amount', 16, 4)->nullable();  // what was routed on
            $table->text('decided_by')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestampTz('decided_at')->nullable();

            $table->timestamps();

            $table->index('payment_id');
            $table->index('status');
        });

        Schema::table('payments', function (Blueprint $table) {
            // Creator's own flag, set alongside Status = "Approved" (Accounts.ds:16132).
            $table->boolean('approved')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('approved');
        });
        Schema::dropIfExists('pending_approvals');
        Schema::dropIfExists('approval_level_approver');
        Schema::dropIfExists('approval_levels');
        Schema::dropIfExists('approvals');
    }
};
