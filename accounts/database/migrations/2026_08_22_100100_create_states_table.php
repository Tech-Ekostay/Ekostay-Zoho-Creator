<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * admin.State — §3.2.
 *
 * creator_id is the 18-digit Creator record id, held as a string end to end
 * (§1.1). It is nullable because not every master export carries an ID column:
 * the TDS report has none at all (addendum §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();
            $table->text('name');                      // source: State
            $table->string('ekostay_id', 24)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('states');
    }
};
