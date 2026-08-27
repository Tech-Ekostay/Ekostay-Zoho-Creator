<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * admin.Location — §3.2.
 *
 * Ten locations, not the five every handover document claims: Kodaikanal and
 * Bangalore are real, and `Head Office Central` is used as a Location value
 * (addendum §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();
            $table->text('name');                      // source: Location
            $table->foreignId('head_office_id')->nullable()->constrained('head_offices')->nullOnDelete();
            $table->foreignId('state_id')->nullable()->constrained('states')->nullOnDelete();
            $table->string('ekostay_id', 24)->nullable();
            $table->boolean('active')->default(true);
            $table->text('circle_ids')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
