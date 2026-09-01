<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * The per-table importers for `fnb:import`.
 *
 * One method per table, written out rather than generated. A generic mapper would
 * hide the rule each table carries — and every one of these carries at least one.
 *
 * Analytics field names come from `php artisan zoho:inspect <view>`, verbatim.
 * §11 measured that key names are per-view and unpredictable, and the other team's
 * conclusion was they "could never predict it, only discover it per view". So the
 * keys here are copied from an inspect run, never guessed.
 */
trait ImportsFnbTables
{
    private function importUoms(array $rows): void
    {
        $now = now();
        $out = [];

        foreach ($rows as $r) {
            // NOT TRIMMED. `Pieces ` is a live lookup key that 70 items join to.
            $name = $r['UOM'] ?? null;
            if ($name === null || $name === '') {
                continue;
            }

            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'name' => $name,
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        $untrimmed = collect($out)->filter(fn ($u) => $u['name'] !== trim($u['name']))->count();
        if ($untrimmed > 0) {
            $this->line("  {$untrimmed} UOM(s) carry edge whitespace — kept as stored");
        }

        $this->put('fnb_uoms', $out, 'uom');
    }

    private function importItems(array $rows): void
    {
        $now = now();
        $out = [];

        foreach ($rows as $r) {
            $name = $r['Item Name'] ?? null;
            if ($name === null || $name === '') {
                continue;
            }

            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'item_name' => $name,
                // Analytics returns lookups as record ids, so this resolves by
                // creator_id rather than by the name string the CSV forced.
                'item_category_id' => $this->look('cat', $r['Item Category'] ?? null, 'item_categories (item master)'),
                'fnb_uom_id' => $this->look('uom', $r['UOM'] ?? null, 'fnb_uoms (item master)'),
                'base_price' => $this->money($r['Base Price'] ?? null),
                // A percentage, stored undivided: Creator holds 5 for 5%.
                'variance' => $this->money($r['Variance'] ?? null),
                // Absent from this view. The CSV had it; left false rather than
                // guessed, and flagged below.
                'no_decimal_values' => $this->bool($r['No Decimal Values'] ?? null),
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        if (! array_key_exists('No Decimal Values', $rows[0] ?? [])) {
            $this->warn(
                '  `No Decimal Values` is NOT in this view — every row imports as false. '
                .'The CSV export has it. Re-seed from CSV if that flag matters.'
            );
        }

        $this->put('fnb_item_masters', $out, 'item');
    }

    private function importWarehouses(array $rows): void
    {
        $now = now();
        $out = [];
        $locations = [];

        foreach ($rows as $r) {
            $name = $r['Warehouse Name'] ?? null;
            if ($name === null || $name === '') {
                continue;
            }

            $cid = $this->id($r['ID'] ?? null);

            $out[] = [
                'creator_id' => $cid,
                'warehouse_name' => $name,
                // This view returns Location and State as NAMES, not ids — unlike
                // most. Hence the by-name maps.
                'state_id' => $this->lookByName('state_by_name', $r['State'] ?? null, 'states (warehouse)'),
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];

            // Location is a multi-value `list` in Creator. THIS VIEW POPULATES IT
            // where the CSV export flattened it to nothing — so the pivot can be
            // filled after all. Villa Name is still blank on every row.
            $loc = $this->lookByName('location_by_name', $r['Location'] ?? null, 'locations (warehouse)');
            if ($cid !== null && $loc !== null) {
                $locations[] = ['creator_id' => $cid, 'location_id' => $loc];
            }
        }

        $this->put('fnb_warehouses', $out, 'warehouse');

        // The pivot, once the warehouses have local ids.
        if (! $this->dry && $locations !== []) {
            DB::table('fnb_warehouse_locations')->truncate();
            $pivot = [];
            foreach ($locations as $l) {
                $wid = $this->map['warehouse'][$l['creator_id']] ?? null;
                if ($wid !== null) {
                    $pivot[] = ['fnb_warehouse_id' => $wid, 'location_id' => $l['location_id']];
                }
            }
            if ($pivot !== []) {
                DB::table('fnb_warehouse_locations')->insert($pivot);
                $this->info('  wrote '.count($pivot).' warehouse-location rows (the CSV had none)');
            }
        }

        $villaBlank = collect($rows)->every(fn ($r) => trim((string) ($r['Villa Name'] ?? '')) === '');
        if ($villaBlank) {
            $this->warn(
                '  Villa Name is blank on EVERY row here too — a multi-value list field '
                .'flattened by Analytics (spec §12). fnb_warehouse_villas stays empty; '
                .'it needs a form-level export.'
            );
        }
    }

    private function importInventories(array $rows): void
    {
        $now = now();
        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'fnb_warehouse_id' => $this->look('warehouse', $r['Warehouse Name'] ?? null, 'fnb_warehouses (inventory)'),
                'item_category_id' => $this->look('cat', $r['Item Category'] ?? null, 'item_categories (inventory)'),
                'fnb_item_master_id' => $this->look('item', $r['Item Name'] ?? null, 'fnb_item_masters (inventory)'),
                'fnb_uom_id' => $this->look('uom', $r['UOM'] ?? null, 'fnb_uoms (inventory)'),
                'available_qty' => $this->money($r['Available Qty'] ?? null),
                'price' => $this->money($r['Price'] ?? $r['Base Price'] ?? null),
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        $this->put('fnb_inventories', $out, 'inventory');
    }

    private function importInventoryStocks(array $rows): void
    {
        $now = now();
        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'fnb_inventory_id' => $this->look('inventory', $r['Inventory'] ?? null, 'fnb_inventories (stock)'),
                // Creator's field is `Date_field` because Date is reserved in Deluge.
                'stock_date' => $this->date($r['Date'] ?? $r['Date field'] ?? null),
                'quantity' => $this->money($r['Quantity'] ?? null),
                'fnb_uom_id' => $this->look('uom', $r['UOM'] ?? null, 'fnb_uoms (stock)'),
                'price' => $this->money($r['Price'] ?? null),
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        $this->put('fnb_inventory_stocks', $out, 'inventory_stock');
    }

    private function importPriceLists(array $rows): void
    {
        $now = now();
        $out = [];
        $seen = [];

        foreach ($rows as $r) {
            $item = $this->look('item', $r['Item Name'] ?? null, 'fnb_item_masters (price list)');
            $vendor = $this->look('vendor', $r['Vendor Name'] ?? null, 'vendors (price list)');

            // The table is unique on (item, vendor). A duplicate pair in the source
            // is dropped and counted rather than allowed to abort the whole import.
            $key = $item.':'.$vendor;
            if ($item !== null && $vendor !== null) {
                if (isset($seen[$key])) {
                    $this->unresolved['duplicate (item, vendor) price rows dropped'] =
                        ($this->unresolved['duplicate (item, vendor) price rows dropped'] ?? 0) + 1;

                    continue;
                }
                $seen[$key] = true;
            }

            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'fnb_item_master_id' => $item,
                'item_category_id' => $this->look('cat', $r['Item Category'] ?? null, 'item_categories (price list)'),
                'vendor_id' => $vendor,
                'price' => $this->money($r['Price'] ?? null),
                'deviation' => $this->money($r['Deviation'] ?? null),
                'price_log' => $r['Price Log'] ?? null,
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        $this->put('fnb_vendor_price_lists', $out, 'price_list');
    }

    private function importChefs(array $rows): void
    {
        $now = now();
        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                // PII, all four of these.
                'name' => $r['Name'] ?? null,
                'chef_id' => $r['Chef ID'] ?? null,
                // The view calls it `Phone`, not `Phone Number`. Both read, because
                // §11 measured that key names are per-view and unpredictable.
                'phone_number' => ($r['Phone'] ?? $r['Phone Number'] ?? '') ?: null,
                'email' => ($r['Email'] ?? '') ?: null,
                // Creator's `address` type EXPLODES into seven columns here:
                // Address Line 1/2, City/District, State/Province, Postal Code,
                // Country, latitude, longitude. Joined back into one text field —
                // the parts are not separately meaningful for a chef record, and
                // seven columns for an address nobody queries is worse.
                'address' => $this->joinAddress($r),
                'location_id' => $this->look('location', $r['Location'] ?? null, 'locations (chef)'),
                'state_id' => $this->look('state', $r['State'] ?? null, 'states (chef)'),
                'ekostay_id' => $this->id($r['Ekostay ID'] ?? null),
                /*
                 * EMPTY STRING IS NOT NULL, and the CHECK caught it: Analytics
                 * returns `Status: ""` where no status was set, and
                 * `status IN ('Active','Inactive')` rejected '' on the first row.
                 *
                 * The constraint did its job. Coerced to null rather than widened
                 * to admit '' — a blank status is absent, not a third state, and
                 * admitting '' would let a typo through later.
                 */
                'status' => ($r['Status'] ?? '') ?: null,
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        $this->put('fnb_chef_masters', $out, 'chef');
    }

    /**
     * Creator's `address` field type arrives as seven separate columns. Joined,
     * skipping the blanks, so a chef with only a city does not get a string of
     * commas.
     */
    private function joinAddress(array $r): ?string
    {
        $parts = array_filter([
            $r['Address Line 1'] ?? null,
            $r['Address Line 2'] ?? null,
            $r['City/District'] ?? null,
            $r['State/Province'] ?? null,
            $r['Postal Code'] ?? null,
            $r['Country'] ?? null,
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function importRecipes(array $rows): void
    {
        $now = now();
        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'recipe_name' => $r['Recipe Name'] ?? null,
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        $this->put('fnb_recipe_masters', $out, 'recipe');
    }


    /**
     * fb.Vendor_Order_Booking. Findings §8.
     *
     * TOTALS ARE NOT IMPORTED AS GIVEN. 287 live orders have line items exceeding
     * their stored parent total (§9.2), so importing Amount/Grand Total/Total
     * Quantity verbatim would import the discrepancy. They are written from the
     * source here so the ORIGINAL is preserved, then `reconcileOrderTotals()` runs
     * after the line items land and reports every order whose legs disagree.
     * Reporting beats correcting: a silent fix hides a live defect.
     *
     * `Order recived` is ABSENT from this view. It is on the form and in the CSV
     * export. Imports as false and is flagged, rather than guessed.
     */
    private function importOrders(array $rows): void
    {
        $now = now();
        $out = [];
        $malformed = 0;

        foreach ($rows as $r) {
            $orderNo = $r['Order No.'] ?? null;
            $orderFor = $r['Order for'] ?? null;

            /*
             * The 407 malformed rows of §8.2 — no order_for, no number, no vendor,
             * no amount. One had "green peas 5rs" typed into Particulars. REJECTED
             * and counted, never loaded as a zero-value order.
             */
            if (($orderNo === null || $orderNo === '')
                && ($orderFor === null || $orderFor === '')
                && ($r['Vendor Name'] ?? '') === '') {
                $malformed++;

                continue;
            }

            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'order_no' => $orderNo,
                'vendor_id' => $this->look('vendor', $r['Vendor Name'] ?? null, 'vendors (order)'),
                'order_for' => $orderFor ?: null,
                'order_date' => $this->date($r['Order Date'] ?? null),
                // fb.Booking belongs to another app — held as a string, not an FK.
                'booking_no' => $this->id($r['Booking No.'] ?? null),
                'fnb_warehouse_id' => $this->look('warehouse', $r['Warehouse Name'] ?? null, 'fnb_warehouses (order)'),
                'location_id' => $this->look('location', $r['Location'] ?? null, 'locations (order)'),
                'state_id' => $this->look('state', $r['State'] ?? null, 'states (order)'),
                'request_no' => $this->id($r['Request No.'] ?? null),
                'status' => ($r['Status'] ?? '') ?: null,
                // `Payment Inprogress` — lowercase p, the third spelling in the
                // cluster and 477 live rows carry it. Stored verbatim.
                'payment_status' => ($r['Payment Status'] ?? '') ?: null,
                'payment_due_date' => $this->date($r['Payment Due Date'] ?? null),
                'billing_year' => ($r['Billing year'] ?? '') === '' ? null : (int) $r['Billing year'],
                'billing_month' => ($r['Billing Month'] ?? '') ?: null,
                'billing_cycle_id' => $this->look('cycle', $r['Billing Cycle'] ?? null, 'billing_cycles (order)'),
                'total_quantity' => $this->money($r['Total Quantity'] ?? null),
                'amount' => $this->money($r['Amount'] ?? null),
                'gst_amount' => $this->money($r['GST Amount'] ?? null),
                'discount' => $this->money($r['Discount'] ?? null),
                'grand_total' => $this->money($r['Grand Total'] ?? null),
                // NOT money-typed: Creator declares it decimal and renders it
                // without a rupee sign, because it is a rounding remainder.
                'adjusted_amount' => $this->money($r['Adjusted Amount'] ?? null),
                'paid_amount' => $this->money($r['Paid Amount'] ?? null),
                'payable_amount' => $this->money($r['Payable Amount'] ?? null),
                'books_id' => $this->id($r['Books ID'] ?? null),
                'update_fulfilled_qty' => $this->bool($r['Update Fulfilled Qty'] ?? null),
                'update_received_qty' => $this->bool($r['Update Received Qty'] ?? null),
                'expense_updated' => $this->bool($r['Expense Updated'] ?? null),
                'order_received' => $this->bool($r['Order recived'] ?? null),
                'particulars' => $r['Particulars'] ?? null,
                'added_user' => $r['Added User'] ?? null,
                'creator_added_time' => $this->timestamp($r['Added Time'] ?? null),
                'modified_user' => $r['Modified User'] ?? null,
                'creator_modified_time' => $this->timestamp($r['Modified Time'] ?? null),
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        if ($malformed > 0) {
            $this->warn(
                "  REJECTED {$malformed} malformed order(s) — no order_for, no number and "
                .'no vendor. Loading them as zero-value orders would put them in every total.'
            );
        }

        if (! array_key_exists('Order recived', $rows[0] ?? [])) {
            $this->warn('  `Order recived` is NOT in this view — imports as false for every row.');
        }

        $this->put('fnb_vendor_order_bookings', $out, 'order');
    }

    /**
     * fb.Vendor_Order_Booking_Item — the Items Ordered grid. Findings §9.
     *
     * THIS VIEW HAS `Fulfilled Quantity`, WHICH THE CSV EXPORT DID NOT (§9.3). So
     * all three quantities are available here: ordered, fulfilled, received. The
     * middle one was invisible to the report export.
     *
     * `amount` follows RECEIVED, not ordered — measured on the 4,523 rows where
     * they differ, 4,438 follow received. The source value is imported as given and
     * then CHECKED rather than recomputed, so a row that disagrees is visible.
     */
    private function importOrderItems(array $rows): void
    {
        $now = now();
        $out = [];
        $followsReceived = 0;
        $followsOrdered = 0;
        $neither = 0;

        foreach ($rows as $r) {
            $ordered = $this->money($r['Ordered Quantity'] ?? null);
            $received = $this->money($r['Received Quantity'] ?? null);
            $price = $this->money($r['Price'] ?? null);
            $amount = $this->money($r['Amount'] ?? null);

            // Which quantity the amount actually followed, counted rather than
            // assumed. Only rows where the two differ can tell them apart.
            if ($amount !== null && $price !== null && $ordered !== null && $received !== null
                && bccomp($ordered, $received, 4) !== 0) {
                if (bccomp(bcmul($received, $price, 4), $amount, 2) === 0) {
                    $followsReceived++;
                } elseif (bccomp(bcmul($ordered, $price, 4), $amount, 2) === 0) {
                    $followsOrdered++;
                } else {
                    $neither++;
                }
            }

            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                // PARENT_ID is Analytics' own link to the parent row — the cleanest
                // join this project has had, since it needs no name matching at all.
                'fnb_vendor_order_booking_id' => $this->look('order', $r['PARENT_ID'] ?? null, 'fnb_vendor_order_bookings (order item)'),
                'fnb_item_master_id' => $this->look('item', $r['Item Name'] ?? null, 'fnb_item_masters (order item)'),
                'item_category_id' => $this->look('cat', $r['Item Category'] ?? null, 'item_categories (order item)'),
                'fnb_uom_id' => $this->look('uom', $r['UOM'] ?? null, 'fnb_uoms (order item)'),
                'ordered_quantity' => $ordered,
                'fulfilled_quantity' => $this->money($r['Fulfilled Quantity'] ?? null),
                'received_quantity' => $received,
                'price' => $price,
                'amount' => $amount,
                'tax_id' => $this->look('tax', $r['GST'] ?? null, 'taxes (order item)'),
                'gst_amount' => $this->money($r['GST Amount'] ?? null),
                'total_amount' => $this->money($r['Total Amount'] ?? null),
                'villa_id' => $this->look('villa', $r['Villa'] ?? null, 'villas (order item)'),
                'line_date' => $this->date($r['Date'] ?? null),
                'raw_material_request_no' => $this->id($r['Raw Material Request'] ?? null),
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        $this->put('fnb_vendor_order_booking_items', $out, 'order_item');

        $this->line(sprintf(
            '    amount follows RECEIVED on %s rows, ordered on %s, neither on %s '
            .'(only rows where the two differ can tell)',
            number_format($followsReceived), number_format($followsOrdered), number_format($neither)
        ));

        if ($followsOrdered > $followsReceived) {
            $this->error(
                '  The measured rule has FLIPPED: amount now follows ORDERED more often '
                .'than received. §9.1 concluded the opposite from 4,523 rows. Re-measure '
                .'before trusting any order total.'
            );
        }

        $this->reconcileOrderTotals();
    }

    /**
     * Compare each order's stored total against the sum of its legs.
     *
     * §9.2 measured 287 orders where the legs exceed the parent — all in the same
     * direction, consistent with a line added after the parent was last computed.
     * They are REPORTED, not corrected: silently fixing a live discrepancy hides it
     * from the people who could investigate it.
     */
    private function reconcileOrderTotals(): void
    {
        if ($this->dry) {
            return;
        }

        $stale = DB::select(
            "SELECT o.order_no, o.amount AS parent, SUM(i.amount) AS legs
             FROM fnb_vendor_order_bookings o
             JOIN fnb_vendor_order_booking_items i
               ON i.fnb_vendor_order_booking_id = o.id
             GROUP BY o.id, o.order_no, o.amount
             HAVING ABS(COALESCE(o.amount,0) - COALESCE(SUM(i.amount),0)) > 0.05"
        );

        if ($stale === []) {
            $this->info('  every order total agrees with its line items');

            return;
        }

        $legsHigher = count(array_filter($stale, fn ($s) => (float) $s->legs > (float) $s->parent));

        $this->warn(sprintf(
            '  %d order(s) whose stored total disagrees with their line items '
            .'(%d have legs HIGHER than the parent). Reported, not corrected — '
            .'recomputeTotals() is the authority at read time.',
            count($stale), $legsHigher
        ));

        foreach (array_slice($stale, 0, 3) as $s) {
            $this->line(sprintf('    %-24s parent %s vs legs %s', $s->order_no, $s->parent, $s->legs));
        }
    }


    /**
     * billing_cycles — the REAL master, 83 rows.
     *
     * `accounts/CLAUDE.md` lists this under "not seeded, no export exists". It does
     * exist: `Billing Cycles` (443703000001623110) in the accounts workspace.
     *
     * `FnbBillingCycleSeeder` recovered 14 cycles by parsing the names that appear
     * on orders, which was the best available at the time and is now superseded —
     * the master has 83, going back to 2023. An order referencing a 2023 cycle had
     * nowhere to resolve to.
     *
     * MonthIndex arrives as `202310`, a year-month key, NOT a 1-12 month number.
     * Our column is `month_index` and holds 1-12, so it is derived from the month
     * NAME rather than taken from this field. Reading it directly would put 202310
     * in a smallint and either overflow or silently wrap.
     *
     * BOTH FEBRUARY SPELLINGS SURVIVE. `Feburary` carries 847 orders against 34
     * for `February` (§12). They are separate rows because they are separate live
     * lookup keys, and the month index is the same for both.
     */
    private function importBillingCycles(array $rows): void
    {
        static $months = [
            'January' => 1, 'February' => 2, 'Feburary' => 2, 'March' => 3,
            'April' => 4, 'May' => 5, 'June' => 6, 'July' => 7, 'August' => 8,
            'September' => 9, 'October' => 10, 'November' => 11, 'December' => 12,
        ];

        $now = now();
        $out = [];
        $unknownMonth = [];

        foreach ($rows as $r) {
            $month = trim((string) ($r['Month'] ?? ''));
            if ($month === '') {
                continue;
            }

            $index = $months[$month] ?? null;
            if ($index === null) {
                // A spelling we have not seen. Imported with a null index rather
                // than dropped — the row is still a real lookup target — but
                // reported, because ordering by month will be wrong for it.
                $unknownMonth[$month] = true;
            }

            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'month_name' => $month,          // verbatim, misspelling included
                'year' => (string) ($r['Year'] ?? ''),
                'month_index' => $index,
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        // Not put(): billing_cycles is an ACCOUNTS table with foreign keys from
        // bills and payments. Truncating it would cascade into their data, so this
        // upserts on creator_id and leaves anything already linked alone.
        if ($this->dry) {
            $this->line('  would upsert '.count($out).' billing cycles');

            return;
        }

        $written = 0;
        foreach ($out as $row) {
            if ($row['creator_id'] === null) {
                continue;
            }
            DB::table('billing_cycles')->updateOrInsert(
                ['creator_id' => $row['creator_id']],
                $row
            );
            $written++;
        }

        // The 14 recovered rows have no creator_id and would now be duplicates of
        // 14 of these 83. Matched by (month_name, year) and removed, but ONLY where
        // nothing references them.
        $orphans = DB::table('billing_cycles')->whereNull('creator_id')->get();
        $removed = 0;
        foreach ($orphans as $o) {
            $replacement = DB::table('billing_cycles')
                ->whereNotNull('creator_id')
                ->where('month_name', $o->month_name)
                ->where('year', $o->year)
                ->value('id');

            if ($replacement === null) {
                continue;   // no real row to replace it with — keep it
            }

            /*
             * Every table that points at billing_cycles, discovered rather than
             * listed. The hardcoded version named `bills.billing_cycle_id`, which
             * DOES NOT EXIST — bills reference a cycle through the
             * `bill_billing_cycle` pivot. The query threw, the whole removal block
             * aborted, and 14 duplicate cycles survived while the command printed
             * nothing. A list of table names goes stale; information_schema does not.
             */
            $referenced = false;
            foreach ($this->tablesReferencingBillingCycles() as $t) {
                if (DB::table($t)->where('billing_cycle_id', $o->id)->exists()) {
                    $referenced = true;
                    break;
                }
            }

            if ($referenced) {
                continue;   // something points at it; leave it and say so
            }

            DB::table('billing_cycles')->where('id', $o->id)->delete();
            $removed++;
        }

        $this->info("  upserted {$written} billing cycles from the real master");
        if ($removed > 0) {
            $this->line("  removed {$removed} recovered row(s) superseded by the master");
        }

        $this->map['cycle'] = DB::table('billing_cycles')
            ->whereNotNull('creator_id')->pluck('id', 'creator_id')->all();

        if ($unknownMonth !== []) {
            $this->warn(
                '  month spellings not in the map (month_index left NULL): '
                .implode(', ', array_keys($unknownMonth))
            );
        }
    }

    /**
     * Tables carrying a `billing_cycle_id`, read from information_schema.
     *
     * Cached per run. Asking the schema beats maintaining a list: the list was
     * wrong within a day of being written.
     */
    private function tablesReferencingBillingCycles(): array
    {
        static $tables = null;

        if ($tables === null) {
            $tables = collect(DB::select(
                "SELECT table_name FROM information_schema.columns
                 WHERE table_schema = 'public' AND column_name = 'billing_cycle_id'"
            ))->pluck('table_name')->all();
        }

        return $tables;
    }


    /**
     * fb.Request_Stock_for_Food — the kitchen's request. Findings §11.
     *
     * CARRIES GUEST NAMES. Real PII beside villa and stay dates. The model puts
     * `guest_name` in $hidden, and any endpoint reading this needs authorisation —
     * which this app does not have anywhere yet.
     *
     * The view's field names differ from the CSV export's in three places, which is
     * §11 again — key names are per-view and unpredictable:
     *
     *     CSV                  Analytics view
     *     Guest Name           (absent — see below)
     *     Villa Name           Villa
     *     Warehouse Name       Warehouse
     *     Checked In Date      Checkin Date
     *
     * GUEST NAME IS NOT IN THIS VIEW. The CSV export has it; Analytics does not
     * expose it here. So the column stays null on an Analytics import and the CSV
     * remains the only source — worth knowing before anyone concludes the field is
     * unused. Reported at the end of the run rather than passed over.
     */
    private function importRequests(array $rows): void
    {
        $now = now();
        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'request_no' => ($r['Request No.'] ?? '') ?: null,
                // fb.Booking is another app's master — a string, not a foreign key.
                'booking_no' => $this->id($r['Booking No.'] ?? null),
                'booking_number' => ($r['Booking Number'] ?? '') ?: null,
                'villa_id' => $this->look('villa', $r['Villa'] ?? null, 'villas (request)'),
                'location_id' => $this->look('location', $r['Location'] ?? null, 'locations (request)'),
                // The view has BOTH `Warehouse` and `Warehouse ID`. The id is the
                // lookup; the name field is a display string.
                'fnb_warehouse_id' => $this->look('warehouse', $r['Warehouse ID'] ?? $r['Warehouse'] ?? null, 'fnb_warehouses (request)'),
                // Absent from this view. Left null rather than guessed.
                'guest_name' => ($r['Guest Name'] ?? '') ?: null,
                'checked_in_date' => $this->date($r['Checkin Date'] ?? null),
                'check_out_date' => $this->date($r['Checkout Date'] ?? null),
                'chef_name' => ($r['Chef Name'] ?? '') ?: null,
                'status' => ($r['Status'] ?? '') ?: null,
                'request_raised' => $this->bool($r['Request Raised'] ?? null),
                'request_from' => ($r['Request From'] ?? '') ?: null,
                'remarks' => ($r['Remarks'] ?? '') ?: null,
                'added_user' => ($r['Added User'] ?? '') ?: null,
                'creator_added_time' => $this->timestamp($r['Added Time'] ?? null),
                'modified_user' => ($r['Modified User'] ?? '') ?: null,
                'creator_modified_time' => $this->timestamp($r['Modified Time'] ?? null),
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        if (! array_key_exists('Guest Name', $rows[0] ?? [])) {
            $this->warn(
                '  `Guest Name` is NOT in this view — imports as NULL. The CSV export '
                .'has it, so the CSV stays the only source for that column.'
            );
        }

        $this->put('fnb_request_stock_for_foods', $out, 'request');
    }

    /**
     * fb.Raw_Material_Request — one line per item requested. Findings §11.
     *
     * **THE MISLABEL IS THE FIELD NAME HERE.** Creator labels `Item_Name` as
     * `"request n"` (F_B.ds:1980, deviation D-FNB-1), and Analytics has taken that
     * label as the column name: the view's field is literally `requestn`.
     *
     * So the mislabel is not confined to three reports and a print template — it
     * has propagated into the read plane, where anyone importing this table would
     * reasonably map `requestn` to a request number and quietly fill the wrong
     * column with item ids. Mapped explicitly to `fnb_item_master_id` with the
     * reason attached.
     *
     * The `_1` fields are Creator's `Warehouse_Name1` and `Vendor_Name1` — hidden
     * working fields holding the alternative source (§11.2), stored as `alt_*`.
     */
    private function importRawMaterials(array $rows): void
    {
        $now = now();
        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'fnb_request_stock_for_food_id' => $this->look('request', $r['Request No.'] ?? null, 'requests (raw material)'),
                'request_no' => ($r['Request No.'] ?? '') ?: null,
                // `requestn`, not `Item Name`. See the docblock.
                'fnb_item_master_id' => $this->look('item', $r['requestn'] ?? null, 'fnb_item_masters (raw material)'),
                'item_category_id' => $this->look('cat', $r['Item Category'] ?? null, 'item_categories (raw material)'),
                // Creator declares UOM as `type = text` on this ONE form while every
                // other UOM field is a picklist. Stored as text, not an FK.
                'uom_text' => ($r['UOM'] ?? '') ?: null,
                'original_requested_quantity' => $this->money($r['Original Requested Quantity'] ?? null),
                'requested_quantity' => $this->money($r['Requested Quantity'] ?? null),
                'delivered_quantity' => $this->money($r['Delivered Quantity'] ?? null),
                'pending_quantity' => $this->money($r['Pending Quantity'] ?? null),
                'available_quantity' => $this->money($r['Available Quantity'] ?? null),
                'warehouse_quantity' => $this->money($r['Warehouse Quantity'] ?? null),
                // Not in this view; it is one of the three hidden fields.
                'backend_warehouse_quantity' => $this->money($r['Backend Warehouse Quantity'] ?? null),
                'order_quantity' => $this->money($r['Order Quantity'] ?? null),
                'request_from' => ($r['Request From'] ?? '') ?: null,
                'fnb_warehouse_id' => $this->look('warehouse', $r['Warehouse Name'] ?? null, 'fnb_warehouses (raw material)'),
                'vendor_id' => $this->look('vendor', $r['Vendor Name'] ?? null, 'vendors (raw material)'),
                // The hidden alternative-source pair.
                'alt_fnb_warehouse_id' => $this->look('warehouse', $r['Warehouse Name_1'] ?? null, 'fnb_warehouses (raw material alt)'),
                'alt_vendor_id' => $this->look('vendor', $r['Vendor Name_1'] ?? null, 'vendors (raw material alt)'),
                'all_vendors' => $this->bool($r['All Vendors'] ?? null),
                'warehouse_updated' => $this->bool($r['Warehouse Updated'] ?? null),
                'request_raised' => $this->bool($r['Request Raised'] ?? null),
                'booking_no' => $this->id($r['Booking No.'] ?? null),
                'request_no_partial' => ($r['Request No Partial'] ?? '') ?: null,
                'request_no_completed' => ($r['Request No Completed'] ?? '') ?: null,
                'added_user' => ($r['Added User'] ?? '') ?: null,
                'creator_added_time' => $this->timestamp($r['Added Time'] ?? null),
                'creator_modified_time' => $this->timestamp($r['Modified Time'] ?? null),
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        if (array_key_exists('requestn', $rows[0] ?? [])) {
            $this->warn(
                '  The Creator mislabel has reached Analytics: this view\'s item column is '
                .'named `requestn`, from `displayname = "request n"` at F_B.ds:1980. '
                .'Mapped to fnb_item_master_id, NOT to a request number (D-FNB-1).'
            );
        }

        $this->put('fnb_raw_material_requests', $out, 'raw_material');
    }

    private function importTransactions(array $rows): void
    {
        $now = now();
        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'fnb_item_master_id' => $this->look('item', $r['Item Name'] ?? null, 'fnb_item_masters (transaction)'),
                'fnb_warehouse_id' => $this->look('warehouse', $r['Warehouse Name'] ?? null, 'fnb_warehouses (transaction)'),
                'transaction_type' => $r['Transaction Type'] ?? null,
                // fb.Booking is another app's master — held as a string, not an FK.
                'order_no' => $this->id($r['Order No.'] ?? null),
                'fnb_vendor_order_booking_id' => $this->look('order', $r['Vendor Order Booking'] ?? null, 'fnb_vendor_order_bookings (transaction)'),
                'fnb_vendor_order_booking_item_id' => $this->look('order_item', $r['Vendor Order Booking Item'] ?? null, 'order items (transaction)'),
                'transfer_to_fnb_warehouse_id' => $this->look('warehouse', $r['Transfer to Warehouse'] ?? null, 'fnb_warehouses (transfer-to)'),
                'fnb_transfer_item_id' => $this->look('transfer', $r['Transfer Items'] ?? null, 'fnb_transfer_items (transaction)'),
                'quantity' => $this->money($r['Quantity'] ?? null),
                'price' => $this->money($r['Price'] ?? null),
                'amount' => $this->money($r['Amount'] ?? null),
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        $this->put('fnb_transaction_items', $out, 'transaction');

        // The ledger's own census, reported because it is the shape of the data
        // rather than a defect: 7,218 of 68,322 live rows are `Reverse`, so stock
        // corrections are common and are done as new rows, never as edits.
        $types = collect($out)->countBy('transaction_type')->sortDesc();
        foreach ($types as $t => $n) {
            $this->line(sprintf('    %-12s %s', $t ?: '(blank)', number_format($n)));
        }
    }

    private function importTransfers(array $rows): void
    {
        $now = now();
        $out = [];

        foreach ($rows as $r) {
            $from = $this->look('warehouse', $r['From Warehouse'] ?? null, 'fnb_warehouses (transfer from)');
            $to = $this->look('warehouse', $r['To Warehouse'] ?? null, 'fnb_warehouses (transfer to)');

            // The CHECK refuses a self-transfer. Creator's picklist excludes the
            // source so this should never fire — if it does, the constraint has
            // found something and the row is reported rather than dropped silently.
            if ($from !== null && $from === $to) {
                $this->unresolved['self-transfer rows REFUSED by the CHECK'] =
                    ($this->unresolved['self-transfer rows REFUSED by the CHECK'] ?? 0) + 1;

                continue;
            }

            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'transfer_id' => $r['Transfer ID'] ?? null,
                'from_fnb_warehouse_id' => $from,
                'to_fnb_warehouse_id' => $to,
                'status' => $r['Status'] ?? null,
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        $this->put('fnb_transfer_items', $out, 'transfer');
    }

    private function importMonthlyChecks(array $rows): void
    {
        $now = now();
        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'checked_by_employee_id' => $this->look('employee', $r['Checked By'] ?? null, 'employees (monthly check)'),
                'fnb_warehouse_id' => $this->look('warehouse', $r['Warehouse'] ?? null, 'fnb_warehouses (monthly check)'),
                'location_id' => $this->look('location', $r['Location'] ?? null, 'locations (monthly check)'),
                'status' => $r['Status'] ?? null,
                'check_date' => $this->date($r['Date'] ?? $r['Date field'] ?? null),
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        $this->put('fnb_monthly_checks', $out, 'monthly_check');
    }

    private function importFoodOrders(array $rows): void
    {
        $now = now();
        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'booking_no' => $this->id($r['Booking No.'] ?? null),
                'meal_name' => $r['Meal Name'] ?? null,
                'guest_count' => ($r['Guest Count'] ?? '') === '' ? null : (int) $r['Guest Count'],
                'meal_details' => $r['Meal Details'] ?? null,
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        $this->put('fnb_food_order_details', $out, 'food_order');
    }

    private function importBlockDates(array $rows): void
    {
        $now = now();
        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'block_date' => $this->date($r['Date'] ?? $r['Date field'] ?? null),
                'created_at' => $this->timestamp($r['Added Time'] ?? null) ?? $now,
                'updated_at' => $this->timestamp($r['Modified Time'] ?? null) ?? $now,
            ];
        }

        $this->put('fnb_block_booking_dates', $out, 'block_date');
    }
}
