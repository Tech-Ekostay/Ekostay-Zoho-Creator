<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * fb.Warehouse — where stock physically sits. F_B.ds:3798.
 *
 * Creator declares Location and Villa_Name as `type = list` (multi-value) over
 * `admin.Location.ID` and `admin.Villa.ID`, and State as a single picklist over
 * `admin.State.ID`. So a warehouse can serve SEVERAL villas and locations.
 *
 * The multi-value fields are therefore pivot tables, not columns — and §12 of the
 * Accounts spec is the reason to care: Zoho Analytics FLATTENS multi-value fields
 * to one silently-chosen value on export. A single `villa_id` column here would
 * bake that data loss into the schema. `state_id` stays a column because Creator
 * declares it singular.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fnb_warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();

            $table->text('warehouse_name');

            // Singular in Creator, so a column.
            $table->foreignId('state_id')->nullable()
                ->constrained('states')->nullOnDelete();

            $table->timestamps();
            $table->index('warehouse_name');
        });

        // Creator: Location = list over admin.Location.ID — many per warehouse.
        Schema::create('fnb_warehouse_locations', function (Blueprint $table) {
            $table->foreignId('fnb_warehouse_id')->constrained('fnb_warehouses')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->primary(['fnb_warehouse_id', 'location_id']);
        });

        // Creator: Villa_Name = list over admin.Villa.ID — many per warehouse.
        Schema::create('fnb_warehouse_villas', function (Blueprint $table) {
            $table->foreignId('fnb_warehouse_id')->constrained('fnb_warehouses')->cascadeOnDelete();
            $table->foreignId('villa_id')->constrained('villas')->cascadeOnDelete();
            $table->primary(['fnb_warehouse_id', 'villa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fnb_warehouse_villas');
        Schema::dropIfExists('fnb_warehouse_locations');
        Schema::dropIfExists('fnb_warehouses');
    }
};
