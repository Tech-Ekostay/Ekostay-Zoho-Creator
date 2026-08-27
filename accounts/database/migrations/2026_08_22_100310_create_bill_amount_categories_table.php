<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `Amount_Category` grid — §6.2.
 *
 * Invoice line items, each carrying its own tax. That reading is DS-backed, not
 * inferred: the Bills validation selects and validates GST per Amount_Category
 * row while summing amounts per Split_Payment row (addendum §10).
 *
 * Two live validations attach to this table, both from OnInputValidationCE:
 *  - gst_needed on the bill blocks any row whose tax is a zero-percentage record
 *  - if the vendor has a GST number, EVERY row must carry a tax selection
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_amount_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->text('bill_for')->nullable();
            $table->decimal('amount', 16, 4)->nullable();
            $table->foreignId('tax_id')->nullable()->constrained('taxes')->nullOnDelete();
            $table->decimal('gst_amount', 16, 4)->nullable();
            $table->decimal('total_amount', 16, 4)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['bill_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_amount_categories');
    }
};
