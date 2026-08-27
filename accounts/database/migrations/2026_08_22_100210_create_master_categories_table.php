<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounts.Master_Category — §4 / §4.2.
 *
 * `fb` is a BOOLEAN and F&B scoping must use it, not a string comparison against
 * the name. The expense tracker filtered `master_category == 'F&B'`, which is why
 * BAKERY and KIRANA kept leaking.
 *
 * Note for whoever tests this: in the 12-Aug-2026 export the flag is true on
 * exactly one row, whose name also happens to be 'F&B', so a name comparison
 * would coincidentally pass today. It is still wrong — the flag is the contract.
 *
 * haewaya_id is empty on all 10 rows, as it is on all 135 item categories: the
 * entire Haewaya sync key is unpopulated (addendum §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_categories', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();
            $table->text('name');
            $table->boolean('fb')->default(false);
            $table->string('haewaya_id', 24)->nullable();
            $table->timestamps();

            $table->index('fb');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_categories');
    }
};
