<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounts.Item_Category — §4 / §4.3.
 *
 * The single source of truth for expense classification. The expense tracker was
 * building a parallel Settings table for the same concepts; do not.
 *
 * `name` is NOT trimmed and NOT unique. `F&B STAFF MEDICAL EXPENSE ` carries a
 * trailing space and that string is a live lookup key (addendum §3). Normalising
 * it here would break joins that currently work.
 *
 * `disable` is labelled `Disallow Manual Creation` on screen (addendum §8). It is
 * a visibility filter on manual entry paths — it stops the category being picked
 * during manual bill/payment entry while leaving it available to the sync and the
 * generators. It is NOT a soft delete.
 *
 * expense_type is unset on 103 of 135 rows, so nullable is the normal case, not
 * an edge case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();
            $table->text('name');                       // significant whitespace — do not trim
            $table->foreignId('master_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('expense_type', 32)->nullable();  // {Direct, Indirect}; unset on 103/135
            $table->boolean('exclude_for_profit')->default(false);
            $table->boolean('exclude_for_observation')->default(false);
            $table->boolean('exclude_item_category')->default(false);
            $table->boolean('disable')->default(false);      // label: Disallow Manual Creation
            $table->decimal('variance', 8, 3)->nullable();   // rendered with a % suffix box
            $table->foreignId('coa_account_id')->nullable()->constrained('coa_accounts')->nullOnDelete();
            $table->foreignId('bank_coa_account_id')->nullable()->constrained('coa_accounts')->nullOnDelete();
            $table->string('haewaya_id', 24)->nullable();    // empty on all 135
            $table->timestamps();

            $table->index('expense_type');
            $table->index('disable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_categories');
    }
};
