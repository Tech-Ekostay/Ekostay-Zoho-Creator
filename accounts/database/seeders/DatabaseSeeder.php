<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * §17 step 2 — the master layer, seeded from master-data/.
 *
 * Order matters: master_categories before item_categories, and ca_masters is
 * created inside CoaAccountSeeder from the CA Name values on the COA export.
 *
 * NOT seeded, because no export exists for them yet: states, head_offices,
 * employee_designations, employee_departments, employees, vendors,
 * billing_cycles, permissions. Villas and locations are seeded NAME-ONLY from
 * recovered files — see those seeders' docblocks.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            GeographySeeder::class,
            VillaSeeder::class,
            MasterCategorySeeder::class,
            ItemCategorySeeder::class,
            CoaAccountSeeder::class,
            TaxSeeder::class,
            TdsRateSeeder::class,
            EmployeeSeeder::class,
            // 8,063 real vendors. Runs after GeographySeeder and
            // MasterCategorySeeder, whose rows it resolves against, and after
            // VillaSeeder because it adds the one Location the villa export
            // does not contain (Alleppey — see the seeder).
            VendorSeeder::class,
            // The payment-number counter (§7.2). Must be real: it is at 20938, and
            // starting fresh would re-issue numbers that already exist.
            AutoNumberSeeder::class,

            // F&B masters. Last, because fnb_item_masters resolves its categories
            // against Accounts' item_categories and its UOMs against fnb_uoms —
            // both must already exist. It creates NO master rows of its own.
            // billing_cycles, recovered from the F&B order export — the only source
            // for them, and they must exist BEFORE any order references one (§6.4:
            // Creator auto-creates a missing cycle, which is how a junk "9-2026"
            // cycle reached live accounting).
            FnbBillingCycleSeeder::class,
            FnbMasterSeeder::class,

            // Warehouses and inventory. AFTER FnbMasterSeeder: it resolves items
            // against fnb_item_masters and UOMs against fnb_uoms, and creates
            // neither if a name is unknown.
            FnbInventorySeeder::class,

            // F&B's own four counters, separate from Accounts' auto_numbers. The
            // values are the REAL live maximums; starting at 1 would re-mint
            // numbers that already belong to orders.
            FnbAutoNumberSeeder::class,
        ]);
    }
}
