<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Approvers grid arrived, and `approval_levels` had nowhere to put the approver.
 *
 * `ApprovalRouter` was written to REFUSE routing because "the amount bands and approver
 * identities are in the Approval form's subform grid and are in no export we hold". The
 * bands were modelled anyway; the identity was not, because there was nothing to put in
 * it. `zoho:views` found `Approval_Approvers` (443703000001623470) on 28-Aug-2026 and
 * the export carries 24 rows across the 16 rules:
 *
 *     PARENT_ID  Level    Minimum      Maximum          Approver                  Type
 *     07201046   Level 1  2,000.00     5,000.00         Rohan - rohan.ops@…       (blank)
 *     07201046   Level 2  5,001.00     500,000,000.00   Varun Arora - varun@…     Any
 *     08783118   Level 1  3,001.00     5,000.00         Husain Super Admin - …    All
 *
 * ---------------------------------------------------------------------------
 * THE APPROVER IS `Name - email` AND THE EMAIL IS THE JOIN KEY.
 *
 * §18's standing lesson is that names do not join — 328 vendor names carry edge
 * whitespace, two of them tabs. The email does join: all three approvers checked on
 * 27-Aug-2026 matched `employees.email` exactly (§11.7). So the email is parsed out and
 * resolved to an employee, and **the raw string is kept alongside** for the same reason
 * `pending_approval_approvers.approver_name` is kept: an approver who leaves may lose
 * their employee row while the rules that name them remain (§6).
 *
 * A null `approver_employee_id` beside a non-null `approver` is therefore a fact, not a
 * gap — the same shape as §18.1's vendor-merge pointer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_levels', function (Blueprint $table) {
            /*
             * The grid's `Approver` verbatim, `Name - email`. Stored unparsed as well as
             * resolved, because this is the audit trail for who a rule pointed at.
             */
            $table->text('approver')->nullable();

            // Parsed out of the above and matched on `employees.email`, which is the
            // key that actually joins.
            $table->foreignId('approver_employee_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            /*
             * The subform row's own id and its parent's, both from Analytics. `PARENT_ID`
             * is how a subform export links to its parent record, and it is the only
             * thing that makes 24 loose rows attributable to 16 rules. §15.2: an
             * 18-digit id is a STRING.
             */
            $table->string('creator_id', 20)->nullable()->unique();
            $table->string('creator_parent_id', 20)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('approval_levels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approver_employee_id');
            $table->dropColumn(['approver', 'creator_id', 'creator_parent_id']);
        });
    }
};
