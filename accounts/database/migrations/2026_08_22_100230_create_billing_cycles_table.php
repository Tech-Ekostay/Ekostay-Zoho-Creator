<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounts.Billing_Cycles — §4.
 *
 * month_name holds the full English month name and year is TEXT in the source.
 * Both are kept as declared: month_index is the sortable value, so nothing needs
 * to alphabetise a month name or cast the year. §15.2 records a live fault where
 * an alphabetical sort was applied to a date-like string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();
            $table->text('month_name');                 // full English month name
            $table->text('year');                       // TEXT in source
            $table->unsignedTinyInteger('month_index')->nullable();
            $table->timestamps();

            $table->index(['year', 'month_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_cycles');
    }
};
