<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * fb.UOM — F&B units of measure. `fnb/FNB_DS_FINDINGS.md` §1, F_B.ds:3040.
 *
 * The smallest table in the app: two fields, and one of them is a section header.
 * It exists as its own table because Item_Master, Inventory, Inventory_Stock and
 * Vendor_Order_Booking_Item all pick from `UOM.ID` — nine picklist references in
 * total, so the values are shared and must not be inlined per form.
 *
 * `name` IS NOT TRIMMED. The live export carries `Pieces ` with a trailing space,
 * and per CLAUDE.md's no-normalising rule that is a live lookup key: normalise at
 * display, never in data. The uniqueness index therefore has to tolerate `Pieces`
 * and `Pieces ` coexisting, which is why it is NOT unique on name — see below.
 *
 * Prefixed `fnb_` because §2.1 puts F&B in the SAME schema as Accounts and Admin,
 * and both apps have tables whose names would otherwise collide (`expenses` above
 * all). One schema, distinct prefixes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fnb_uoms', function (Blueprint $table) {
            $table->id();

            // 18-character Creator id, kept as a STRING. A float cast rounds the
            // last three digits away — F_B.ds itself carries 311 such corrupted
            // ids (findings §4.2), so this is a demonstrated failure, not a
            // theoretical one.
            $table->string('creator_id', 32)->nullable()->unique();

            // No unique index: `Pieces` and `Pieces ` are two rows in the source
            // and both are real keys. A unique constraint here would force a
            // silent trim on import, which is the one thing the rule forbids.
            $table->text('name');

            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fnb_uoms');
    }
};
