<?php

declare(strict_types=1);

namespace App\Services\Zoho;

use RuntimeException;

/**
 * The Analytics view registry — from §6 of `docs/ZOHO_ANALYTICS_CONNECTION.md`.
 *
 * Views are addressed by NUMERIC ID, not by name, so without this every call site
 * would carry an 18-digit literal with no way to tell `443703000001635133` from
 * `443703000001635079` by reading it. Names here are ours; ids and warnings are
 * theirs, verbatim.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS REGISTRY MAKES VISIBLE, and it is the important part:
 *
 * **There is no Bills view.** The accounts workspace has Expenses, Bill link check,
 * Bank transactions, Banks, COA, Payment (master), Location, Villa, Personal
 * Expenses and F&B. No bills. So a bill, as §6 of the rebuild spec means it, is not
 * directly exportable — `expenses` is the closest thing and its relationship to a
 * bill has to be established by inspection before anything is imported.
 *
 * **`all_payments` is unusable and that is documented, not suspected.** §6 marks it
 * "heavy-join QueryTable — TIMES OUT on bulk export; we rebuild it from plain Tables
 * instead". It is registered anyway, flagged, because the failure mode otherwise is
 * a ten-minute poll that ends in the slot-holding pile-up §7.2 warns about. Ask for
 * it and you get told, before the job is created.
 *
 * **`payment_master` is a plain Table**, which is good for export reliability and
 * bad for fidelity: a base-table projection is exactly where §12 of the field notes
 * flattens multi-value fields to one silently-chosen value. A payment here spans
 * villa x category x cycle. So expect headers, and expect the split legs to be
 * missing rather than wrong.
 */
final class ZohoViews
{
    /**
     * @var array<string, array{id: string, workspace: string, label: string,
     *                          large?: bool, avoid?: string, note?: string}>
     */
    private const VIEWS = [
        // ---- accounts workspace: this rebuild's domain -------------------
        'expenses' => [
            'id' => '443703000003471628',
            'workspace' => 'accounts',
            'label' => 'Expenses',
            /*
             * ASSUMED LARGE, before measuring — a deliberate departure from
             * measure-first, and the reasoning is worth keeping.
             *
             * `payment_master` exhausted a 512MB limit as JSON at 52,678 rows, and
             * this view is the LEDGER-ROW side: if it is one row per split leg
             * rather than per bill, it is bigger than payments, not smaller. A
             * wrong guess in the JSON direction costs a shared export slot and a
             * ten-minute poll; a wrong guess toward CSV costs nothing.
             *
             * CSV is also the safer format for this project regardless of size:
             * every value arrives as TEXT, so an 18-digit id cannot be silently
             * turned into a float on the way in.
             */
            'large' => true,
            'note' => 'QueryTable, async export only. The nearest thing to a bill or an '
                .'Expenses_Bills row; which of the two it is has to be established by '
                .'inspection, not assumed.',
        ],
        'bill_link_check' => [
            'id' => '443703000001775406',
            'workspace' => 'accounts',
            'label' => 'Bill link check',
            'note' => 'payment_no -> bill links. The only view that appears to carry the '
                .'bill/payment relationship.',
        ],
        'payment_master' => [
            'id' => '443703000000062677',
            'workspace' => 'accounts',
            'label' => 'Payment (master)',
            /*
             * LARGE — measured here, 25-Aug-2026, and NOT flagged as such in the
             * connection guide. The guide marks only `bookings` and
             * `booking_payment_type` large; this one exported fine and then
             * exhausted a 512MB PHP limit inside json_decode of the downloaded
             * body. So the CSV rule generalises further than §7.4 documents: the
             * two views it names are the ones that bit them, not the whole set.
             *
             * Treat any unmeasured view as potentially large rather than assuming
             * the guide's list is exhaustive.
             */
            'large' => true,
            'note' => 'Plain Table — exports reliably, but a base-table projection is where '
                .'multi-value fields get flattened (§12). Headers, probably not legs. '
                .'LARGE: exhausted a 512MB limit as JSON, so it streams as CSV.',
        ],
        /*
         * Supplied by Husain, 01-Sep-2026, as a workspace/view URL rather than a bare
         * id — which is the right way to give one, because the id alone resolves
         * against the DEFAULT workspace and fails for the wrong reason if it belongs
         * to `live`. Its workspace matches ZOHO_WORKSPACE_ACCOUNTS exactly.
         *
         * This is the FIRST sync path these masters have ever had. Until now
         * `item_categories` came only from `master-data/All_Item_Categories.json`, a
         * hand-export dated 22-Aug-2026, and nothing in `app/` ever wrote the table.
         * The importers only ever read it to resolve foreign keys, counting misses in
         * `item_category_unresolved` — so a category added in Creator silently failed
         * to resolve and no one was told.
         */
        'item_category' => [
            'id' => '443703000000062605',
            'workspace' => 'accounts',
            'label' => 'Item Categories',
            'note' => '135 rows as of the 22-Aug CSV. Carries `Exclude for Profit` and '
                .'`Exclude for Observation` as booleans, and the Creator report renders '
                .'them as the text true/false, NOT as checkboxes.',
        ],
        /*
         * ---- Eko_RS_* : the statement/reconciliation subsystem -----------------
         *
         * Six views, all supplied by Husain on 01-Sep-2026 and all in `accounts`.
         * Supplying them settles the scope question: this subsystem IS part of the
         * rebuild, not Tushar's.
         *
         * Their ids share the `4437030000082443xx` block, which is a much later
         * series than the rest of this registry (`44370300000xxxxxx`) — so they were
         * created together, long after the original workspace. Treat them as one
         * unit: a partial sync of a statement subsystem is worse than none, because
         * a `Send_Log` without its `Statements` reads as "nothing was ever sent".
         *
         * NONE of the six is measured for row count yet. §7.2's rule is that an
         * unmeasured view is potentially `large`, so measure before the first bulk
         * export rather than discovering it in a slot-holding pile-up.
         */
        'eko_rs_statements' => [
            'id' => '443703000008244323',
            'workspace' => 'accounts',
            'label' => 'Eko RS Statements',
            'note' => '17 fields in the DS — the largest of the six and the parent of the '
                .'subsystem. Unmeasured; may be large.',
        ],
        'eko_rs_app_config' => [
            'id' => '443703000008244356',
            'workspace' => 'accounts',
            'label' => 'Eko RS App Config',
            'note' => 'Configuration, expected small. 14 fields.',
        ],
        'eko_rs_flags' => [
            'id' => '443703000008244301',
            'workspace' => 'accounts',
            'label' => 'Eko RS Flags',
            'note' => '13 fields.',
        ],
        'eko_rs_send_log' => [
            'id' => '443703000008244470',
            'workspace' => 'accounts',
            'label' => 'Eko RS Send Log',
            'note' => 'A log table: the one most likely to be large, and the one whose row '
                .'count grows without bound. Measure first. 11 fields.',
        ],
        'eko_rs_settings' => [
            'id' => '443703000008244257',
            'workspace' => 'accounts',
            'label' => 'Eko RS Settings',
            'note' => 'Configuration, expected small. 10 fields.',
        ],
        'eko_rs_pdf_staging' => [
            'id' => '443703000008244459',
            'workspace' => 'accounts',
            'label' => 'Eko RS Pdf Staging',
            'note' => 'Only 2 fields in the DS, but staging tables often carry a blob or a '
                .'long path. Check the column shape before assuming it is cheap.',
        ],
        'master_category' => [
            'id' => '443703000000062587',
            'workspace' => 'accounts',
            'label' => 'Master Categories',
            'note' => '10 rows. Carries `F_B`, the scoping flag the whole F&B app depends '
                .'on — `master_categories.fb` is true on `F&B` alone, and it lives on the '
                .'ACCOUNTS side. Small table, load-bearing.',
        ],
        /*
         * ⚠️ PII. `.gitignore` excludes `master-data/Vendor_Master.csv*` by name — twice —
         * because these rows carry PANs and free-text bank details. Syncing into local
         * Postgres is fine; EXPOSING it is not. §3.3's authorisation matrix is extracted
         * and tested but still not wired to a gate, so any endpoint reading this table
         * would be open. Do not add one until that gate exists.
         *
         * Merge semantics also apply: §13A.1 is settled, and a merge is NEVER resolved
         * through `main_primary`. The Creator form's own lookup filters on
         * `Vendor_Master[Main_Primary.Main_Primary is not null]`, which is the trap.
         */
        'vendor_master' => [
            'id' => '443703000000062659',
            'workspace' => 'accounts',
            'label' => 'Vendor Master',
            'note' => '8,161 vendors, 55 fields. PII: PANs and bank details. 6,957 are '
                .'selectable as trade payees. Unmeasured for export size.',
        ],
        'tax' => [
            'id' => '443703000001623380',
            'workspace' => 'accounts',
            'label' => 'Tax',
            'note' => 'Creator filters this as `Tax[Tax_Type == "tax_group"]` for the F&B '
                .'cross-app read, so tax_type matters and must survive the import.',
        ],
        'tds' => [
            'id' => '443703000001623164',
            'workspace' => 'accounts',
            'label' => 'TDS',
            'note' => '35 rows seeded locally as `tds_rates`. Creator filters '
                .'`TDS[Status == "Active"]` on the Bills form, so Status must come across.',
        ],
        'salary_payouts' => [
            'id' => '443703000008244411',
            'workspace' => 'accounts',
            'label' => 'Salary Payouts',
            'note' => 'Same 4437030000082443xx series as the Eko_RS six. Salary Payouts is '
                .'GATED in the rebuild — §11 versioned payroll config does not exist — so '
                .'this syncs for understanding, not to power a screen.',
        ],
        'coa' => [
            'id' => '443703000001623452',
            'workspace' => 'accounts',
            'label' => 'COA',
            'note' => 'Plain Table: ID / Account Name / Account Type. We already hold 144 '
                .'COA rows from CSV, so this is a cross-check rather than a source.',
        ],
        'banks' => [
            'id' => '443703000004394530',
            'workspace' => 'accounts',
            'label' => 'Banks',
            'note' => 'WARNING from §6: its `zoho_id` is a DIFFERENT id series from Creator '
                .'form lookups. Do not join it to a Creator record id.',
        ],
        'bank_transactions' => [
            'id' => '443703000005740362',
            'workspace' => 'accounts',
            'label' => 'Bank transactions',
        ],
        'location' => [
            'id' => '443703000001635079',
            'workspace' => 'accounts',
            'label' => 'Location (Zoho Creator)',
            'note' => 'We hold 30 locations, 29 from the villa export plus Alleppey recovered '
                .'from the vendor export. This can confirm whether 30 is the real count.',
        ],
        'villa' => [
            'id' => '443703000001635133',
            'workspace' => 'accounts',
            'label' => 'Villa (Creator)',
            'note' => 'WORTH INSPECTING EARLY. Our villa data came from a REPORT export '
                .'carrying 18 of ~40 fields, and the missing ones include '
                .'Hide_From_Payments — the filter Bills and Payments actually use. If this '
                .'view is form-level it closes a documented gap.',
        ],
        'personal_expenses' => [
            'id' => '443703000005050081',
            'workspace' => 'accounts',
            'label' => 'Personal Expenses (All Sources)',
        ],
        'fnb' => [
            'id' => '443703000002007229',
            'workspace' => 'accounts',
            'label' => 'F&B / kitchen',
            'large' => true,
            'note' => 'F&B is not a future concern — Bills carries an F&B lookup today. '
                .'MEASURED 27-Aug-2026: 27,950 rows, 57 columns, 11.5s. It was NOT flagged '
                .'large, so it took the JSON path and exhausted a 128MB limit in decode() — '
                .'the exact §7.4 failure, on a view nobody had run. Flagged now.',
        ],
        /*
         * ---- F&B raw tables, found 31-Aug-2026 -------------------------------
         *
         * Discovered by LISTING the workspace rather than by asking: 665 views live
         * there, and every F&B source table carries the suffix "(Zoho Creator-F&B)".
         *
         * They are viewType **Table** — a plain projection of the Creator form — not
         * QueryTable. That matters: §6 records that a heavy-join QueryTable is what
         * times out on bulk export (all_payments is flagged avoid for exactly that),
         * and the "(F&B)" QueryTables sitting beside these are reporting joins. Prefer
         * the Tables.
         *
         * NOTE the existing 'fnb' entry above (443703000002007229) is
         * "All Expenses (F&B)" — an expense-shaped join, NOT one of these form
         * tables. Both are kept; they answer different questions.
         */
        /*
         * Two Accounts/Admin masters F&B references BY RECORD ID. Registered here
         * because the F&B order view returns `State: "292482000000169003"` and
         * `Billing Cycle: "292482000004887880"` — ids, not names — and our rows
         * were recovered from CSV names with no creator_id at all. Without these
         * two views, 10,762 of 10,765 orders resolved neither.
         */
        'states' => [
            'id' => '443703000001635097',
            'workspace' => 'accounts',
            'label' => 'State (Zoho Creator)',
        ],
        'billing_cycles' => [
            'id' => '443703000001623110',
            'workspace' => 'accounts',
            'label' => 'Billing Cycles',
            'note' => 'The cycle master CLAUDE.md lists under "no export exists". It '
                .'does exist here. Note BOTH February spellings are live keys - '
                .'`Feburary` carries 847 orders against 34 for `February`.',
        ],

        'fnb_transaction_items' => [
            'id' => '443703000001635845',
            'workspace' => 'accounts',
            'label' => 'Transaction Items (Zoho Creator-F&B)',
            'note' => 'THE STOCK LEDGER. Every movement in/out/transfer/damaged/'
                .'misplaced/reverse. The only view that can prove available_qty is right.',
        ],
        'fnb_vendor_order_bookings' => [
            'id' => '443703000001635899',
            'workspace' => 'accounts',
            'label' => 'Vendor Order Booking (Zoho Creator-F&B)',
            'large' => true,
            'note' => '11,205 rows in the CSV export of the same report.',
        ],
        'fnb_vendor_order_booking_items' => [
            'id' => '443703000001635917',
            'workspace' => 'accounts',
            'label' => 'Vendor Order Booking_Items Ordered (Zoho Creator-F&B)',
            'large' => true,
            'note' => '110,510 rows in the CSV. THE GRID, not the standalone form: '
                .'Creator exposes both a Vendor Order Booking Item view (...935) and '
                .'this Items Ordered subform. This one holds the child rows.',
        ],
        'fnb_raw_material_requests' => [
            'id' => '443703000001635737',
            'workspace' => 'accounts',
            'label' => 'Raw Material Request (Zoho Creator-F&B)',
            'large' => true,
            'note' => '160,995 rows in the CSV - the second largest F&B table.',
        ],
        'fnb_request_stock_for_foods' => [
            'id' => '443703000001635755',
            'workspace' => 'accounts',
            'label' => 'Request Stock for Food (Zoho Creator-F&B)',
            'note' => 'CARRIES GUEST NAMES. Real PII beside villa and stay dates - '
                .'needs authorisation on any endpoint that reads it.',
        ],
        'fnb_inventory_stocks' => [
            'id' => '443703000001635683',
            'workspace' => 'accounts',
            'label' => 'Inventory_Stock (Zoho Creator-F&B)',
            'note' => 'The dated child rows. NOT "Inventory Stock (...647)", which is '
                .'the parent-shaped view - the underscore is the whole difference.',
        ],
        'fnb_vendor_price_lists' => [
            'id' => '443703000001635575',
            'workspace' => 'accounts',
            'label' => 'Vendor Price List (Zoho Creator-F&B)',
        ],
        'fnb_transfer_items' => [
            'id' => '443703000001635863',
            'workspace' => 'accounts',
            'label' => 'Transfer Items (Zoho Creator-F&B)',
        ],
        'fnb_monthly_checks' => [
            'id' => '443703000001635701',
            'workspace' => 'accounts',
            'label' => 'Monthly Check (Zoho Creator-F&B)',
        ],
        'fnb_monthly_check_items' => [
            'id' => '443703000001635719',
            'workspace' => 'accounts',
            'label' => 'Monthly Check_Items List (Zoho Creator-F&B)',
        ],
        'fnb_chef_masters' => [
            'id' => '443703000001635449',
            'workspace' => 'accounts',
            'label' => 'Chef Master (Zoho Creator-F&B)',
            'note' => 'PII: name, phone, email, address.',
        ],
        'fnb_recipe_masters' => [
            'id' => '443703000006354913',
            'workspace' => 'accounts',
            'label' => 'Recipe Master (Zoho Creator-F&B)',
        ],
        'fnb_recipe_requirements' => [
            'id' => '443703000006354877',
            'workspace' => 'accounts',
            'label' => 'Requirements of Recipe (Zoho Creator-F&B)',
            'note' => 'The PARENT. Creator also exposes four category-shaped children - '
                .'KIRANA ...868, DAIRY ...904, VEGETABLE ...886, MEAT ...931 - which are '
                .'the four hardcoded grids of findings 13.2. Our table is '
                .'category-agnostic, so import the parent and the four become queries.',
        ],
        'fnb_food_order_details' => [
            'id' => '443703000006354922',
            'workspace' => 'accounts',
            'label' => 'Food Order Details (Zoho Creator-F&B)',
        ],
        'fnb_block_booking_dates' => [
            'id' => '443703000006354895',
            'workspace' => 'accounts',
            'label' => 'Block Booking Date (Zoho Creator-F&B)',
        ],
        'fnb_auto_numbers' => [
            'id' => '443703000001635428',
            'workspace' => 'accounts',
            'label' => 'Auto Numbers (Zoho Creator-F&B)',
            'note' => 'ONE ROW, and what matters is the counter RIGHT NOW. '
                .'FnbNumber::allocate() refuses while our counter is behind this, so a '
                .'fresh reading re-arms the guard.',
        ],
        'fnb_warehouses' => [
            'id' => '443703000001635611',
            'workspace' => 'accounts',
            'label' => 'Warehouse (Zoho Creator-F&B)',
            'note' => 'Holds the Location and Villa_Name multi-value fields that the CSV '
                .'export flattened to nothing (spec 12). Worth re-checking here.',
        ],
        'fnb_warehouse_items' => [
            'id' => '443703000001635629',
            'workspace' => 'accounts',
            'label' => 'Warehouse_Inventory Items (Zoho Creator-F&B)',
        ],
        'fnb_item_masters' => [
            'id' => '443703000001635539',
            'workspace' => 'accounts',
            'label' => 'Item Master (Zoho Creator-F&B)',
        ],
        'fnb_uoms' => [
            'id' => '443703000001635521',
            'workspace' => 'accounts',
            'label' => 'UOM (Zoho Creator-F&B)',
        ],
        'fnb_inventories' => [
            'id' => '443703000001635665',
            'workspace' => 'accounts',
            'label' => 'Inventory (Zoho Creator-F&B)',
        ],

        'all_payments' => [
            'id' => '443703000001659807',
            'workspace' => 'accounts',
            'label' => 'All Payments',
            'avoid' => 'Heavy-join QueryTable. §6 records that it TIMES OUT on bulk export, '
                .'and the other team rebuilds it from plain Tables instead. Exporting it '
                .'burns a ten-minute poll and then holds an account-wide slot — the exact '
                .'pile-up §7.2 warns about. Use `payment_master` plus the lookup views.',
        ],

        /*
         * GIVEN BY HUSAIN, 26-Aug-2026, as the place to find expenses AND bills:
         * analytics.zoho.in/workspace/443703000004950271/view/443703000004950303
         *
         * NOTE THE WORKSPACE. It is 443703000004950271 — `live`, not `accounts`.
         * The connection guide's §6 lists neither this view nor anything bill-shaped
         * in either workspace, so it is a view that guide does not cover. That also
         * means a raw numeric id would have been resolved against the DEFAULT
         * workspace (`accounts`) and failed for the wrong reason, which is why it is
         * registered rather than passed through.
         *
         * Assumed large until measured: every accounts-side view so far has been
         * bigger than the guide implies, and CSV costs nothing when it is not.
         */
        'expenses_bills' => [
            'id' => '443703000004950303',
            'workspace' => 'live',
            'label' => 'Expenses & Bills',
            'large' => true,
            'note' => 'Husain-supplied. The candidate source for BILLS, which the accounts '
                .'workspace has no view for at all.',
        ],

        /*
         * ---- THE CREATOR FORM TABLES, discovered 28-Aug-2026 -----------------
         *
         * `zoho:views` enumerated the workspace through the METADATA api (no export
         * slot) and found 413 Tables where this registry knew 16. Every Accounts form
         * this rebuild has been documenting has a table, and two of them correct
         * standing claims in our own code and docs:
         *
         *   `Approval_Approvers`      `ApprovalRouter` refuses to route because "the
         *                             amount bands and approver identities are in no
         *                             export we hold". They are in this view.
         *   `Payment_Split Payments`  the CHILD rows. §12 says never import from a
         *                             one-row-per-parent view, and the reason
         *                             `payments.villa_id` is null on 52,637 of 52,639
         *                             rows is that we imported the parent only.
         *
         * SIZE IS UNMEASURED FOR ALL OF THESE. Every accounts-side view measured so
         * far has been larger than expected — `payment_master` OOM'd at 512MB — so the
         * subform tables are flagged `large` on the same precautionary basis, and the
         * genuinely small config tables are not.
         */

        // The counter. One row, and the reason it is here: `auto_numbers.payment_no`
        // must be reconciled from a LIVE read at cutover, not from a stale export
        // (addendum §6.13). This view IS that live read.
        'auto_numbers' => [
            'id' => '443703000001623488',
            'workspace' => 'accounts',
            'label' => 'Auto Numbers',
            'note' => 'ONE ROW: the four payment series. Cheapest useful export in the '
                .'account and the input to the cutover takeover.',
        ],

        /*
         * ---- BILLS, and a standing claim corrected ------------------------
         *
         * §Zoho-connection notes say: "There is no Bills view in the accounts workspace.
         * `expenses` is the nearest candidate and whether it is a bill or an
         * `Expenses_Bills` row is unestablished." That was written against a 16-view
         * registry. **There is one**, with both child grids, found 28-Aug-2026.
         *
         * The cost of not knowing: `zoho:import-bills` RECONSTRUCTS bills from the
         * expenses export, and the 17,161 rows it produced are 17,158 `Paid` plus three
         * odds — no `Draft`, no `Overdue`, no `Partially Paid`. So the Payment form's
         * Bill No picker, which offers exactly those three statuses, has nothing to show
         * for any vendor. The statuses were lost in the reconstruction, not absent live.
         */
        'bills' => [
            'id' => '443703000000062641',
            'workspace' => 'accounts',
            'label' => 'Bills',
            'large' => true,
            'note' => 'THE REAL BILLS TABLE. Import this rather than reconstructing from '
                .'`expenses` — reconstruction produced 17,158 Paid and no Draft, which '
                .'empties the Payment form Bill No picker.',
        ],
        'bills_amount_category' => [
            'id' => '443703000001623416',
            'workspace' => 'accounts',
            'label' => 'Bills_Amount Category (line items)',
            'large' => true,
            'note' => 'The `Amount_Category` of §6.2 — line items, distinct from the split '
                .'allocation. The DS settled that difference; this is the data.',
        ],
        'bills_split_payment' => [
            'id' => '443703000001623128',
            'workspace' => 'accounts',
            'label' => 'Bills_Split Payment (the allocation grid)',
            'large' => true,
            'note' => 'villa x category x cycle per bill — the grid `SplitAllocator` owns.',
        ],

        // ---- approvals ---------------------------------------------------
        'approval' => [
            'id' => '443703000001623056',
            'workspace' => 'accounts',
            'label' => 'Approval (rule headers)',
            'note' => '16 rules. Module / Level 1 & 2 Approval / Level 2 & 3 Approval and '
                .'the comma-packed scope columns.',
        ],
        'approval_approvers' => [
            'id' => '443703000001623470',
            'workspace' => 'accounts',
            'label' => 'Approval_Approvers (the grid)',
            'note' => 'THE GRID ApprovalRouter WAS BUILT TO REFUSE WITHOUT. Level, Minimum '
                .'Amount, Maximum Amount, Approver, Approval Type. Registering it does not '
                .'change the router: the header fields it routes on are a browser-side '
                .'mirror of this grid (§11.9), so importing this makes the divergence '
                .'measurable rather than inferred.',
        ],
        'pending_approvals' => [
            'id' => '443703000001623434',
            'workspace' => 'accounts',
            'label' => 'Pending Approvals',
            'large' => true,
            'note' => 'The live queue is over 1,000 rows and never clears (§5), so this is '
                .'large despite being a work queue.',
        ],
        'pending_approvals_approved_by' => [
            'id' => '443703000001623182',
            'workspace' => 'accounts',
            'label' => 'Pending Approvals_Approved By (subform)',
            'large' => true,
            'note' => 'One row per approver per level. The report flattens it to one name '
                .'(§5); this is what it flattens from.',
        ],
        'preferred_approver' => [
            'id' => '443703000001623038',
            'workspace' => 'accounts',
            'label' => 'Preferred Approver',
        ],

        // ---- the payment child grids, which is where the detail lives ----
        'payment_split_payments' => [
            'id' => '443703000001623074',
            'workspace' => 'accounts',
            'label' => 'Payment_Split Payments (the legs)',
            'large' => true,
            'note' => 'THE CHILD ROWS §12 SAYS TO IMPORT. villa x item category x billing '
                .'cycle per payment. Expect >100k rows against 52,639 payments, and expect '
                .'it to answer why villa and billing cycle are blank on every parent.',
        ],
        'payment_bill_payments' => [
            'id' => '443703000001623254',
            'workspace' => 'accounts',
            'label' => 'Payment_Bill Payments (which bills a payment settles)',
            'large' => true,
            'note' => 'The grid whose Payable_Amount is CLAMPED to the outstanding balance '
                .'rather than computed (§6.3, resolved 28-Aug-2026). Distinct from '
                .'Split Payments.',
        ],
        'payment_bills' => [
            'id' => '443703000001623092',
            'workspace' => 'accounts',
            'label' => 'Payment_Bills',
            'large' => true,
        ],

        // ---- the modules documented in §7A-7I but never sourced -----------
        'backend_expenses' => [
            'id' => '443703000001623326',
            'workspace' => 'accounts',
            'label' => 'Backend Expenses',
            'large' => true,
            'note' => '140 fields, 136 of them text (§13B). Go zero-time dates and packed '
                .'multipe_hccc_names both need handling at ingest, not after.',
        ],
        'backend_payments' => [
            'id' => '443703000001623200',
            'workspace' => 'accounts',
            'label' => 'Backend Payments (the refunds channel)',
            'large' => true,
            'note' => 'REFUND-{product}-{bookingId}, over 1,000 rows (§7.1).',
        ],
        'payment_request' => [
            'id' => '443703000001623308',
            'workspace' => 'accounts',
            'label' => 'Payment Request',
            'note' => '72 rows on the live report (§6). Small.',
        ],
        'expense_observation' => [
            'id' => '443703000001623506',
            'workspace' => 'accounts',
            'label' => 'Expense Observation',
            'large' => true,
            'note' => 'Over 1,000 rows; the report is grouped by villa with subtotals (§7C).',
        ],
        'payments_scheduled' => [
            'id' => '443703000001623398',
            'workspace' => 'accounts',
            'label' => 'Payments Scheduled',
            'large' => true,
            'note' => 'Schedule Payments is still gated on §11 payroll configuration. This '
                .'is the data, not the permission to build the screen.',
        ],
        'bank_transactions_matching' => [
            'id' => '443703000001623362',
            'workspace' => 'accounts',
            'label' => 'Bank Transactions_Matching Transactions (subform)',
            'large' => true,
            'note' => 'The related list that read "No records found" on the sampled bank '
                .'transaction (§7B.1).',
        ],

        /*
         * NOT REGISTERED, deliberately, though `zoho:views` found them:
         *
         *   `payment_testing`, `payment_extractions`, `paymentlinks`,
         *   `payment_response_for_website`, `pending_balances_cih`, `count_requests`
         *       another application's operational tables, not Creator forms.
         *
         *   `payment_process_locks`
         *       LOOKS RELEVANT and is left alone until understood. §7I.2 flagged a
         *       `Sync Locks Report`; if this is the same mechanism then our scheduler
         *       should READ it rather than invent a second one, and reading it wrongly
         *       is worse than not reading it.
         *
         *   every `(Zoho Books)` view
         *       the Books plane is org 60040119506 and a different tenant (§7B.5).
         *
         *   the Pivots, QueryTables, AnalysisViews and Dashboards
         *       derived, and `all_payments` is the standing lesson about exporting a
         *       heavy-join QueryTable.
         */

        // ---- live workspace: another app's domain, registered for completeness
        'bookings' => [
            'id' => '443703000005403993',
            'workspace' => 'live',
            'label' => 'Bookings',
            'large' => true,
            'note' => '~114k rows. CSV streaming only — JSON OOM\'d their server (§7.4).',
        ],
        'booking_payment_type' => [
            'id' => '443703000005403901',
            'workspace' => 'live',
            'label' => 'Booking payment type',
            'large' => true,
            'note' => '~221k rows. CSV streaming only.',
        ],
        'sales' => [
            'id' => '443703000005432349',
            'workspace' => 'live',
            'label' => 'Sales (sale_name -> name)',
        ],
        'debit_statement' => [
            'id' => '443703000005431379',
            'workspace' => 'live',
            'label' => 'Debit statement (recoverables)',
        ],
        'crm_ocr' => [
            'id' => '443703000006789303',
            'workspace' => 'live',
            'label' => 'CRM payment extractions (OCR)',
            'note' => '`ok` renders as Yes/No, not 1/0.',
        ],
    ];

    /**
     * The views worth looking at first for THIS project, in order, and why.
     *
     * @return list<string>
     */
    public static function inspectionOrder(): array
    {
        return [
            // We hold zero real payments — everything in `payments` is a fixture.
            'payment_master',
            // The nearest thing to a bill, and the only candidate for split legs.
            'expenses',
            // Could close the documented Hide_From_Payments gap.
            'villa',
            // The bill/payment relationship.
            'bill_link_check',
        ];
    }

    /** @return array{id: string, workspace: string, label: string, large?: bool, avoid?: string, note?: string} */
    public static function get(string $name): array
    {
        // A raw numeric id is accepted so an unregistered view can still be
        // inspected — that is how a view gets registered in the first place.
        if (preg_match('/^\d{10,}$/', $name) === 1) {
            return [
                'id' => $name,
                'workspace' => (string) config('services.zoho.workspace', 'accounts'),
                'label' => 'unregistered view '.$name,
            ];
        }

        if (! isset(self::VIEWS[$name])) {
            throw new RuntimeException(sprintf(
                "Unknown Analytics view '%s'. Registered: %s. A raw numeric view id also works.",
                $name,
                implode(', ', array_keys(self::VIEWS)),
            ));
        }

        return self::VIEWS[$name];
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::VIEWS;
    }

    public static function workspaceId(string $workspace): string
    {
        $id = config('services.zoho.workspaces.'.$workspace);

        if (blank($id)) {
            throw new RuntimeException(
                "No id configured for Analytics workspace '{$workspace}'. The two on this "
                .'instance are `accounts` and `live`; they hold different views and their '
                .'ids are not interchangeable.'
            );
        }

        return (string) $id;
    }

    /**
     * Refuse a schedule minute that belongs to the other application.
     *
     * §7.1 of the connection guide: the export concurrency limit is account-wide and
     * shared with the expense tracker, and a collision "will break *both* apps'
     * syncs" — it once caused a two-day stall. Their minutes are :00, :12, :24, :42
     * and :48. This exists so that fact is enforced by code the day someone adds a
     * scheduled sync, rather than remembered from a document nobody re-reads.
     *
     * Being clear about the limit of this guard: it prevents the KNOWN collisions
     * only. It cannot see their actual job table, and §7.1 asks for the schedule to
     * be agreed with Tushar directly. Do that as well.
     */
    public static function assertScheduleIsClear(?\Illuminate\Support\Carbon $at = null): void
    {
        $at ??= \Illuminate\Support\Carbon::now();

        $zone = (string) config('services.zoho.foreign_cron_timezone', 'Asia/Kolkata');

        self::assertMinuteIsClear((int) $at->copy()->setTimezone($zone)->format('i'));
    }

    /**
     * The primitive: is this minute-of-hour, **already expressed in the foreign cron's
     * timezone**, one of theirs?
     *
     * ---------------------------------------------------------------------------
     * THE TIMEZONE IS THE WHOLE POINT, AND THIS GUARD GOT IT WRONG UNTIL 28-Aug-2026.
     *
     * `app.timezone` is **UTC** and the expense tracker's cron runs on **IST**, which is
     * UTC+5:30 — an offset with a THIRTY MINUTE component. So a minute-of-hour is not
     * the same number in both zones, and the guard, which compared `Carbon::now()`'s UTC
     * minute against IST cron minutes, protected the wrong slots:
     *
     *     his cron (IST)   :00  :12  :24  :42  :48
     *     the same in UTC   :30  :42  :54  :12  :18
     *     guard blocked    :00  :12  :24  :42  :48   <- UTC
     *
     * It therefore blocked :00, :24 and :48 for no reason, and **left :18, :30 and :54
     * unprotected — three of his five slots.** Only :12 and :42 were covered, and only
     * by the coincidence that they differ by exactly 30.
     *
     * Found by noticing that `zoho:sync` printed `(:05)` while the shell clock said
     * `:35`. The exports that ran did so at IST :35 and :37, clear in either reading, so
     * nothing collided — but the guard had not been protecting what it claimed to.
     *
     * The lesson is narrow and worth keeping: **a cron minute is a wall-clock minute in
     * somebody's timezone.** Comparing it against another zone's minute is only safe
     * where the offset is a whole number of hours, and India's is not.
     */
    public static function assertMinuteIsClear(int $minute): void
    {
        $taken = (array) config('services.zoho.foreign_cron_minutes', []);
        $zone = (string) config('services.zoho.foreign_cron_timezone', 'Asia/Kolkata');

        if (in_array($minute, $taken, true)) {
            throw new RuntimeException(sprintf(
                'Minute :%02d %s belongs to the expense tracker (its minutes: %s). The Analytics '
                .'export concurrency limit is ACCOUNT-WIDE, not per application, so running here '
                .'would compete for the same slots and can break both apps — it caused a '
                .'two-day stall once. Wait for another minute AND agree any schedule with Tushar.',
                $minute,
                $zone,
                implode(', ', array_map(fn ($m) => sprintf(':%02d', $m), $taken)),
            ));
        }
    }
}
