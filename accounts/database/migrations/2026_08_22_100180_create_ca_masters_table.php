<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * admin.CA_Master — §3.2.
 *
 * CA_Master.Bank points at accounts.COA[Account_Type == "bank"] while COA.CA_Name
 * points back at CA_Master, so the two tables are mutually dependent. This
 * migration creates the table without that FK; 100201 adds it once coa_accounts
 * exists.
 *
 * Only two CA names appear in the live COA export at all — Jitesh (6 accounts)
 * and Keshav (1) — against 144 accounts (addendum §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ca_masters', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();
            $table->text('name');
            $table->text('phone')->nullable();
            $table->text('email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ca_masters');
    }
};
