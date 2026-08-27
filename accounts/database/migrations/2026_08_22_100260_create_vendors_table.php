<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounts.Vendor_Master — §4 / §13A.
 *
 * name is NOT unique: `amazon` and `Amazon` are two records for one vendor
 * (addendum §6), and Payment Requests has two rows approved with a blank vendor
 * name at all. A unique index would reject live data.
 *
 * The handoff does accept a duplicate-name CHECK at the application layer as one
 * of the few additions allowed — that is a warning on the form, not a constraint
 * here.
 *
 * §13A.1 leaves it open which of the three vendor-merge fields is authoritative,
 * so merge state is deliberately not modelled yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();
            $table->text('name');                        // not unique — see docblock
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('state_id')->nullable()->constrained('states')->nullOnDelete();
            $table->text('phone')->nullable();
            $table->text('upi_id')->nullable();
            $table->text('pan_no')->nullable();
            $table->text('source')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('main_primary')->nullable();
            $table->foreignId('master_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_category_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
