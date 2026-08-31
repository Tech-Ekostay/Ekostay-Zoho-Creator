<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of F&B: the eight remaining forms plus the recipe child table.
 * Findings §13. `fb.Booking` and `fb.Expenses` are deliberately NOT here — see
 * the note at the bottom.
 *
 * One migration rather than eight because none of these carries an argument worth
 * its own file: they are masters and movement logs, and the decisions are per
 * column rather than per table.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * fb.Chef_Master (777) — PII: name, phone, email, address.
         */
        Schema::create('fnb_chef_masters', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();
            $table->text('name')->nullable();
            $table->string('chef_id')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('state_id')->nullable()->constrained('states')->nullOnDelete();
            $table->string('ekostay_id')->nullable();   // a number in Creator, a string here
            $table->string('status')->nullable();
            $table->timestamps();
            $table->index('name');
        });
        DB::statement(
            'ALTER TABLE fnb_chef_masters ADD CONSTRAINT fnb_chef_status_check '
            ."CHECK (status IS NULL OR status IN ('Active','Inactive'))"
        );

        /*
         * fb.Recipe_Master (2238) — one field. The requirements live in the child
         * table below.
         */
        Schema::create('fnb_recipe_masters', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();
            $table->text('recipe_name')->nullable();
            $table->timestamps();
            $table->index('recipe_name');
        });

        /*
         * fb.Requirements_of_Recipe (2547) — CATEGORY-AGNOSTIC ON PURPOSE.
         *
         * Creator has FOUR grids, each filtered by a literal category name:
         * KIRANA, DAIRY, VEGETABLES, MEAT. Those reach 335 of 370 items and leave
         * 35 unreachable — F&B GENERAL PURCHASE (20), FRUITS (8), F&B TRANSPORT
         * (3), F&B GAS (3), BAKERY (1). A recipe cannot name a fruit.
         *
         * Whether it should is a business question (TODO-FNB-6), so this is ONE
         * child table keyed by item rather than four category-shaped ones. The four
         * grids become four filtered queries over the same rows, and if the answer
         * is "all categories" that is a query change, not a migration.
         */
        Schema::create('fnb_recipe_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();
            $table->foreignId('fnb_recipe_master_id')->nullable()
                ->constrained('fnb_recipe_masters')->cascadeOnDelete();
            $table->foreignId('fnb_item_master_id')->nullable()
                ->constrained('fnb_item_masters')->nullOnDelete();
            $table->decimal('quantity', 16, 4)->nullable();
            $table->foreignId('fnb_uom_id')->nullable()->constrained('fnb_uoms')->nullOnDelete();
            $table->timestamps();
            $table->index(['fnb_recipe_master_id', 'fnb_item_master_id']);
        });

        /*
         * fb.Food_Order_Details (1432) — what a booking ordered to eat.
         */
        Schema::create('fnb_food_order_details', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();
            $table->string('booking_no')->nullable();      // fb.Booking, by number
            $table->text('meal_name')->nullable();
            $table->unsignedInteger('guest_count')->nullable();
            $table->text('meal_details')->nullable();
            $table->timestamps();
            $table->index('booking_no');
        });

        /*
         * fb.Vendor_Price_List (3653) — price per vendor per item.
         *
         * `Vendor_Name` filters `accounts.Vendor_Master[Vendor_Category.ID ==
         * input.Item_Category]`, which looked wrong and is not: `Vendor_Category`
         * holds Item_Category.ID (Accounts.ds:11479). Two scoping mechanisms on one
         * table — `Master_Category.F_B` for "is an F&B vendor", `Vendor_Category`
         * for "supplies this category". Findings §4.5.
         */
        Schema::create('fnb_vendor_price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();
            $table->foreignId('fnb_item_master_id')->nullable()
                ->constrained('fnb_item_masters')->cascadeOnDelete();
            $table->foreignId('item_category_id')->nullable()
                ->constrained('item_categories')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->decimal('price', 16, 4)->nullable();
            $table->decimal('deviation', 8, 4)->nullable();     // percentage, undivided
            // Creator's Price_Log is richtext and Price_Logs a grid. Kept as text
            // until an export shows what the grid holds — inventing a shape for an
            // audit trail is worse than storing the text Creator already writes.
            $table->text('price_log')->nullable();
            $table->timestamps();
            $table->unique(['fnb_item_master_id', 'vendor_id'], 'fnb_price_item_vendor_unique');
        });

        /*
         * fb.Transfer_Items (2923) — warehouse to warehouse.
         */
        Schema::create('fnb_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();
            $table->string('transfer_id')->nullable();
            $table->foreignId('from_fnb_warehouse_id')->nullable()
                ->constrained('fnb_warehouses')->nullOnDelete();
            $table->foreignId('to_fnb_warehouse_id')->nullable()
                ->constrained('fnb_warehouses')->nullOnDelete();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->index('transfer_id');
        });
        DB::statement(
            'ALTER TABLE fnb_transfer_items ADD CONSTRAINT fnb_transfer_status_check '
            ."CHECK (status IS NULL OR status IN ('Draft','Transfer Initiated','Transit','Received'))"
        );
        // Creator's To_Warehouse picklist excludes the source
        // (`Warehouse[Warehouse_Name != …]`), so a self-transfer is not reachable
        // through the UI. Enforced here rather than trusted to a picklist.
        DB::statement(
            'ALTER TABLE fnb_transfer_items ADD CONSTRAINT fnb_transfer_not_to_self '
            .'CHECK (from_fnb_warehouse_id IS NULL OR to_fnb_warehouse_id IS NULL '
            .'OR from_fnb_warehouse_id <> to_fnb_warehouse_id)'
        );

        /*
         * fb.Transaction_Items (2755) — THE STOCK LEDGER. Every movement in or out.
         *
         * `Transaction_Type` is the widest picklist in F&B and includes `Reverse`,
         * which is how a mistake is undone — the same shape as the payment reversal
         * Accounts built for D4. So stock is never edited backwards; a correction is
         * another row.
         */
        Schema::create('fnb_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();
            $table->foreignId('fnb_item_master_id')->nullable()
                ->constrained('fnb_item_masters')->nullOnDelete();
            $table->foreignId('fnb_warehouse_id')->nullable()
                ->constrained('fnb_warehouses')->nullOnDelete();
            $table->string('transaction_type')->nullable();
            $table->string('order_no')->nullable();          // fb.Booking, by number
            $table->foreignId('fnb_vendor_order_booking_id')->nullable()
                ->constrained('fnb_vendor_order_bookings')->nullOnDelete();
            $table->foreignId('fnb_vendor_order_booking_item_id')->nullable()
                ->constrained('fnb_vendor_order_booking_items')->nullOnDelete();
            $table->foreignId('transfer_to_fnb_warehouse_id')->nullable()
                ->constrained('fnb_warehouses')->nullOnDelete();
            $table->foreignId('fnb_transfer_item_id')->nullable()
                ->constrained('fnb_transfer_items')->nullOnDelete();
            $table->decimal('available_quantity', 16, 4)->nullable();
            $table->decimal('quantity', 16, 4)->nullable();
            $table->decimal('total_quantity', 16, 4)->nullable();
            $table->decimal('price', 16, 4)->nullable();
            $table->decimal('amount', 16, 4)->nullable();
            $table->timestamps();
            $table->index(['fnb_warehouse_id', 'fnb_item_master_id']);
            $table->index('transaction_type');
        });
        DB::statement(
            'ALTER TABLE fnb_transaction_items ADD CONSTRAINT fnb_txn_type_check '
            ."CHECK (transaction_type IS NULL OR transaction_type IN "
            ."('In','Out','Transfer','Damaged','Misplaced','Reverse'))"
        );

        /*
         * fb.Monthly_Check (1809) — a stock count. Its Items_List grid is the child
         * table below.
         */
        Schema::create('fnb_monthly_checks', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();
            $table->foreignId('checked_by_employee_id')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->foreignId('fnb_warehouse_id')->nullable()
                ->constrained('fnb_warehouses')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('status')->nullable();
            $table->date('check_date')->nullable();          // Creator: Date_field
            $table->timestamps();
            $table->index(['fnb_warehouse_id', 'check_date']);
        });
        DB::statement(
            'ALTER TABLE fnb_monthly_checks ADD CONSTRAINT fnb_monthly_status_check '
            ."CHECK (status IS NULL OR status IN ('Draft','Submitted','Finalized'))"
        );

        Schema::create('fnb_monthly_check_items', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();
            $table->foreignId('fnb_monthly_check_id')->nullable()
                ->constrained('fnb_monthly_checks')->cascadeOnDelete();
            $table->foreignId('fnb_item_master_id')->nullable()
                ->constrained('fnb_item_masters')->nullOnDelete();
            $table->decimal('system_quantity', 16, 4)->nullable();
            $table->decimal('counted_quantity', 16, 4)->nullable();
            $table->timestamps();
            $table->index('fnb_monthly_check_id');
        });

        /*
         * fb.Block_Booking_Date (149) — one date, mirroring Accounts'
         * Block_Payment_Date.
         *
         * Accounts' §10 finding: the block is enforced NOWHERE server-side — one
         * browser-side handler and a field-disable rule, nothing on the other forms.
         * The same is expected here, so the table exists and the enforcement is a
         * deliberate open question rather than an assumed behaviour.
         */
        Schema::create('fnb_block_booking_dates', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();
            $table->date('block_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'fnb_block_booking_dates', 'fnb_monthly_check_items', 'fnb_monthly_checks',
            'fnb_transaction_items', 'fnb_transfer_items', 'fnb_vendor_price_lists',
            'fnb_food_order_details', 'fnb_recipe_requirements', 'fnb_recipe_masters',
            'fnb_chef_masters',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
