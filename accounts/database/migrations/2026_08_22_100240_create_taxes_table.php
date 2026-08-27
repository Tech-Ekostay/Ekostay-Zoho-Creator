<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounts.Tax — §4.
 *
 * The source FIELD is misspelled `Tax_Precentage`; the display LABEL is correct,
 * `Tax Percentage` (addendum §1). The column is spelled correctly here on
 * purpose — a scaffold that derives labels from column names would otherwise put
 * the typo back on screen.
 *
 * tax_type holds Books API values: `tax` (all three IGST) and `tax_group` (all
 * five GST). That split is real accounting — a tax_group is CGST + SGST with two
 * ledger destinations while the app stores a single GST_Amount. It is a free-text
 * input on the form, so nothing constrains it and no CHECK is added here.
 *
 * Known gap, addendum §3: IGST exists only at 0/5/18 while GST runs 0/5/12/18/28,
 * so an interstate purchase at 12% or 28% has no entry to select.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();
            $table->text('name');                        // source: Tax Name
            $table->text('tax_type')->nullable();        // Books: tax | tax_group; unconstrained
            $table->decimal('tax_percentage', 8, 3)->nullable();
            $table->string('books_tax_id', 24)->nullable(); // source: Tax ID
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
