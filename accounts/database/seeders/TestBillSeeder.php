<?php

namespace Database\Seeders;

use App\Models\Bill;
use App\Models\BillingCycle;
use App\Models\ItemCategory;
use App\Models\Vendor;
use App\Models\Villa;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * ONE BILL TO TEST A PAYMENT AGAINST. Not real data, and not part of
 * DatabaseSeeder — run it explicitly:
 *
 *     php artisan db:seed --class=TestBillSeeder
 *
 * DELIBERATELY OUTSIDE THE DEFAULT SEED. Every other seeder in this directory
 * loads real Creator exports from master-data/. This one invents a bill, so it
 * must never run as a side effect of `db:seed` and quietly put fabricated money
 * next to the real masters. The vendor it creates is named to be unmistakable.
 *
 * THE FIGURES TIE THE LEGS TO THE GROSS, WHICH IS WHAT §6.4 REQUIRES:
 *
 *     gross (Amount)   100000.00
 *     GST 18%           18000.00
 *     invoice          118000.00      = gross + GST
 *     TDS 2%             2000.00      on the gross, per §10.3's basis question
 *     payable          116000.00      = invoice - TDS      (Accounts.ds:22490)
 *
 * split across two villas, one category, one cycle:
 *
 *     per leg   amount 50000.00   GST 9000.00   TDS 1000.00   total 58000.00
 *     2 x 50000.00 = 100000.00 = the bill's GROSS            <- §6.4 rule 1
 *
 * `total_amount` per leg is `Amount + GST - TDS`, which is the formula §6.3's
 * split-equally uses for that column.
 *
 * AN EARLIER VERSION OF THIS FIXTURE tied the legs to PAYABLE (2 x 58000), which
 * agreed with a bug in CreatePaymentFromBill rather than with §6.4. Both are fixed;
 * the pairing is why neither was caught by a test. A fixture built to satisfy the
 * code under test proves nothing.
 *
 * Round numbers on purpose: an off-by-a-paisa here would be indistinguishable
 * from the arithmetic faults the split tests are meant to catch.
 */
class TestBillSeeder extends Seeder
{
    /** So the fixture is recognisable in a list of real vendors. */
    public const VENDOR_NAME = 'TEST VENDOR — payment flow fixture';

    public function run(): void
    {
        $villas = Villa::query()->selectableForPayments()->orderBy('id')->limit(2)->get();

        if ($villas->count() < 2) {
            throw new RuntimeException(
                'Need two payment-selectable villas for the split. Run the master seeders first.'
            );
        }

        $itemCategory = ItemCategory::query()->orderBy('id')->firstOrFail();

        $vendor = Vendor::firstOrCreate(
            ['name' => self::VENDOR_NAME],
            ['item_category_id' => $itemCategory->id, 'master_category_id' => $itemCategory->master_category_id]
        );

        // billing_cycles has no export yet (see DatabaseSeeder), so the fixture
        // creates the one cycle it needs.
        $cycle = BillingCycle::firstOrCreate(
            ['month_name' => 'August', 'year' => '2026'],
            ['month_index' => 8]
        );

        $bill = Bill::firstOrCreate(
            ['bill_no' => 'TEST/BILL/0001'],
            [
                'bill_date' => '2026-08-22',
                'due_date' => '2026-09-21',
                'vendor_id' => $vendor->id,
                'location_id' => $villas->first()->location_id,
                'status' => 'Draft',
                'split_equally' => true,
                'amount' => '100000.0000',
                'gst_amount' => '18000.0000',
                'tds_amount' => '2000.0000',
                'invoice_amount' => '118000.0000',
                'paid_amount' => '0.0000',
                'payable_amount' => '116000.0000',
            ]
        );

        $bill->itemCategories()->syncWithoutDetaching([$itemCategory->id]);
        $bill->billingCycles()->syncWithoutDetaching([$cycle->id]);
        $bill->villas()->syncWithoutDetaching($villas->pluck('id')->all());

        foreach ($villas->values() as $position => $villa) {
            $bill->splitPayments()->updateOrCreate(
                [
                    'villa_id' => $villa->id,
                    'item_category_id' => $itemCategory->id,
                    'billing_cycle_id' => $cycle->id,
                ],
                [
                    'amount' => '50000.0000',
                    'total_amount' => '58000.0000',
                    'gst_amount' => '9000.0000',
                    'tds_amount' => '1000.0000',

                    // The backend triplet mirrors the live figures while nothing is
                    // paid — addendum §10. §7.2 reads these on a partially-paid bill.
                    'backend_total_amount' => '58000.0000',
                    'backend_gst_amount' => '9000.0000',
                    'backend_tds_amount' => '1000.0000',

                    'percent' => '50.0000',
                    'position' => $position,
                ]
            );
        }

        $this->command?->info(sprintf(
            'Test bill %s ready (id %d) — gross 100000.0000 across %d split legs (§6.4).',
            $bill->bill_no,
            $bill->id,
            $bill->splitPayments()->count(),
        ));
    }
}
