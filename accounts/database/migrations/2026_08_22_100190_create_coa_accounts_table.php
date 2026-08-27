<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounts.COA — §4 / §4.1.
 *
 * A real typed chart of accounts, NOT the VARCHAR(50) enum the downstream expense
 * tracker used. Approval routing branches on account_type (§8.1), and the live
 * export carries all 16 Books account types.
 *
 * Deliberately NOT unique on account_name: `EKOSTAY IDFC LLP` genuinely appears
 * twice with different record ids (addendum §3). A unique index here would reject
 * real data.
 *
 * `hide` is the field labelled `COA` on screen — the label/field divergence in
 * addendum §8, and still a [TODO]: if the boolean labelled `COA` really is
 * `Hide`, then §7.5's "inverted filter" finding dissolves. Both names are kept so
 * whichever way it resolves, no column has to be renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coa_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();
            $table->text('account_name');
            $table->string('account_type', 48)->nullable();  // 16 Books types; 'bank' is load-bearing
            $table->text('account_code')->nullable();        // populated on only 6 of 144
            $table->string('books_account_id', 24)->nullable(); // source: Account ID, 19-digit Books id
            $table->boolean('bank')->default(false);
            $table->boolean('hide')->default(false);         // displayed as `COA` — see docblock
            $table->foreignId('ca_master_id')->nullable()->constrained('ca_masters')->nullOnDelete();
            $table->text('ca_name_source')->nullable();      // verbatim, for mapping
            $table->string('ekostay_id', 24)->nullable();
            $table->timestamps();

            $table->index('account_type');
            $table->index('bank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coa_accounts');
    }
};
