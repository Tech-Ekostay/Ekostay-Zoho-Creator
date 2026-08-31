<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `Added Time`, `Added User`, `Modified Time`, `Modified User` — on every record.
 *
 * THESE ARE PLATFORM FIELDS, NOT APP FIELDS. Husain pointed this out on 27-Aug-2026:
 * Creator maintains all four automatically on every record of every form, the way a
 * database maintains a rowid. They were treated here as ordinary columns and so were
 * added ad hoc — `vendors` and `expenses` had all four because their exports carried
 * them, `payments` had only `added_user` because one code path happened to set it,
 * and the other nineteen tables had none.
 *
 * That is the wrong shape. Every report in Creator can show them, so every table
 * must have them.
 *
 * ---------------------------------------------------------------------------
 * THEY ARE NOT `created_at` / `updated_at`, and both pairs are kept.
 *
 *   created_at / updated_at    when OUR row was written. Reset by a re-seed.
 *   added_time / modified_time when the CREATOR record was created and last touched.
 *                              Imported from the source and preserved across re-seeds.
 *
 * A vendor imported today has `created_at` of today and an `added_time` of whenever
 * someone in accounts actually created it — 22-Aug-2026 18:44:32 for the first one.
 * Collapsing them would destroy the only record of when the business event happened.
 *
 * AND THE USER HALF HAS NO EQUIVALENT AT ALL. `created_at` cannot say WHO. Creator
 * fills these from `zoho.loginuser`; the imported data shows real values —
 * `murali.zoho186`, `mansi.p`, `shibli_ekostayhospitality`, `ekostay`. That is an
 * audit trail, and it is the reason these are worth carrying properly rather than
 * approximating.
 *
 * ---------------------------------------------------------------------------
 * `added_user` AND `modified_user` WILL BE NULL ON ANYTHING CREATED HERE until
 * authorisation exists. There is no session, so there is no user to record. That is
 * a gap, not a design: see `TracksCreatorAudit`, which resolves the user through a
 * container binding so wiring §3.3 fills these in without touching a model.
 *
 * PIVOT TABLES ARE SKIPPED. `bill_villa`, `permission_role` and the rest are join
 * rows, not Creator records — Creator has no form behind them and therefore no audit
 * fields to mirror. Subform tables (`bill_split_payments`, `payment_split_payments`)
 * DO get them: a subform row is a record in Creator and carries its own stamps.
 */
return new class extends Migration
{
    /** Creator forms — every one of these is a record with audit fields. */
    private const TABLES = [
        'approvals',
        'approval_levels',
        'bills',
        'bill_amount_categories',
        'bill_split_payments',
        'billing_cycles',
        'ca_masters',
        'coa_accounts',
        'employees',
        'employee_departments',
        'employee_designations',
        'head_offices',
        'item_categories',
        'locations',
        'master_categories',
        'payments',
        'payment_bill_payments',
        'payment_split_payments',
        'pending_approvals',
        'states',
        'taxes',
        'tds_rates',
        'villas',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                // Added piecemeal before this migration existed, so each is checked
                // rather than assumed absent.
                if (! Schema::hasColumn($table, 'added_time')) {
                    $blueprint->timestampTz('added_time')->nullable();
                }
                if (! Schema::hasColumn($table, 'added_user')) {
                    $blueprint->text('added_user')->nullable();
                }
                if (! Schema::hasColumn($table, 'modified_time')) {
                    $blueprint->timestampTz('modified_time')->nullable();
                }
                if (! Schema::hasColumn($table, 'modified_user')) {
                    $blueprint->text('modified_user')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        /*
         * DELIBERATELY ASYMMETRIC. `vendors` and `expenses` carried these columns
         * before this migration and their values are IMPORTED — real audit data from
         * Creator, not something this migration created. Dropping them on a rollback
         * would destroy imported history that took an export to obtain.
         *
         * So down() leaves those two alone and only removes what up() could have
         * added elsewhere.
         */
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || in_array($table, ['vendors', 'expenses'], true)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach (['added_time', 'added_user', 'modified_time', 'modified_user'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }
};
