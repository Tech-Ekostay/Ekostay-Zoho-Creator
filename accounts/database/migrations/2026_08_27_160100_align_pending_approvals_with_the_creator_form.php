<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align `pending_approvals` with the real Creator form.
 *
 * Seven screenshots of All Pending Approvals arrived 27-Aug-2026 — index, detail and
 * edit — and they change what this record IS.
 *
 * ---------------------------------------------------------------------------
 * THE APPROVERS LIVE ON THE PENDING RECORD, NOT ONLY ON THE RULE. The edit form
 * carries:
 *
 *     Approvers            multi-select of employees, chips  ("Sohail Mirchandani")
 *     Preferred Approver   a single picklist, blank on the sample
 *     Approval Type        Any / All
 *     Approved By          a SUBFORM: Approver x Approval Level x Approved (checkbox)
 *                          with "+ Add New"
 *
 * This was modelled as though the approvers were reachable only through the Approval
 * rule's Approvers grid — which is the export we do not have. They are not: the
 * pending record is a snapshot carrying its own approver list. So APPROVE AND REJECT
 * CAN BE BUILT NOW. The rule's amount bands decide which LEVEL a new payment enters
 * at; they are not needed to move one that is already in flight.
 *
 * That is a material unblock, and it came from a screenshot rather than from asking
 * for the export again.
 *
 * ---------------------------------------------------------------------------
 * `Approved By` IS A SUBFORM, not a name. The index shows it rendered as a single
 * name ("Sohail Mirchandani", one row "Varun Arora") because the report flattens the
 * grid to its first value — the §12 flattening, visible in Creator's own UI. The
 * form shows the truth: one row per approver per level with its own `Approved` flag.
 *
 * That is what makes `Approval Type = All` meaningful. With `Any`, one ticked row
 * advances the level; with `All`, every row must be ticked. A single `decided_by`
 * column cannot express that, so `pending_approval_approvers` gets its own table.
 *
 * ---------------------------------------------------------------------------
 * TWO FIELDS THE INDEX SHOWS THAT NOTHING HERE MODELLED:
 *
 *   `Message ID`   a UUID per row (27718721-51ad-4372-b12e-716bfb5a268c). These are
 *                  the `Messageid` / `Messageid_Level_2` / `Messageid_Level_3` fields
 *                  from the Payment form — outbound WhatsApp message ids, one per
 *                  level. Stored so a notification can be traced; not interpreted.
 *
 *   `Payment Status` sits BESIDE `Status` and shows the same values on every visible
 *                  row (`Sent for Approval`, `Approved`). Two columns carrying one
 *                  concept — reproduced, because the report has both and a reviewer
 *                  comparing side by side will look for both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_approvals', function (Blueprint $table) {
            /*
             * `Approval Type` on the RECORD, not only on the rule. Any / All, and it
             * governs whether one ticked approver advances the level or all must.
             */
            $table->text('approval_type')->nullable()->after('approval_level');

            // A single picklist, blank on the sample. The `Preferred Approver` nav
            // screen presumably maintains whatever fills it.
            $table->foreignId('preferred_approver_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            /*
             * The report shows `Payment Status` beside `Status` with identical values.
             * Kept as its own column: two columns is what the report has, and
             * collapsing them would lose a column a reviewer expects to find.
             */
            $table->text('payment_status')->nullable();

            // One WhatsApp message id per level — Messageid, _Level_2, _Level_3.
            $table->text('message_id')->nullable();
            $table->text('message_id_level_2')->nullable();
            $table->text('message_id_level_3')->nullable();

            $table->string('creator_id', 20)->nullable()->unique();
        });

        /*
         * The `Approved By` subform. One row per approver per level, each with its own
         * `Approved` flag — which is the only shape that can express `Approval Type =
         * All`, where every row must be ticked before the level advances.
         */
        Schema::create('pending_approval_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pending_approval_id')
                ->constrained('pending_approvals')->cascadeOnDelete();

            /*
             * NULLABLE, deliberately. The approver is an Employee_Master record, but
             * an approver who has left may no longer have a row — §6: names drift and
             * deleted records vanish while the history that references them remains.
             * The NAME is stored alongside so the audit survives a missing employee.
             */
            $table->foreignId('employee_id')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->text('approver_name')->nullable();

            // "Level 1" / "Level 2" / "Level 3", compared as strings against
            // pending_approvals.approval_level.
            $table->text('approval_level')->nullable();

            $table->boolean('approved')->default(false);
            $table->timestampTz('approved_at')->nullable();

            $table->integer('position')->default(0);

            // Creator's platform stamps, as on every other form record.
            $table->timestampTz('added_time')->nullable();
            $table->text('added_user')->nullable();
            $table->timestampTz('modified_time')->nullable();
            $table->text('modified_user')->nullable();
            $table->timestamps();

            $table->index(['pending_approval_id', 'approval_level']);
        });

        /*
         * `Approvers` — the multi-select on the record, distinct from `Approved By`.
         *
         * The form shows them as two separate things: `Approvers` is who MAY approve,
         * `Approved By` is the grid recording who HAS. On the sample both hold the
         * same one person, which is exactly the case where a single table would look
         * sufficient and would be wrong the moment a level has two candidates and one
         * of them acts.
         */
        Schema::create('pending_approval_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pending_approval_id')
                ->constrained('pending_approvals')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->text('approver_name')->nullable();
            $table->timestamps();

            $table->unique(['pending_approval_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_approval_candidates');
        Schema::dropIfExists('pending_approval_approvers');

        Schema::table('pending_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_approver_id');
            $table->dropColumn([
                'approval_type', 'payment_status', 'message_id',
                'message_id_level_2', 'message_id_level_3', 'creator_id',
            ]);
        });
    }
};
