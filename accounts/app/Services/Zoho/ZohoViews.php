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
    public static function assertScheduleIsClear(int $minute): void
    {
        $taken = (array) config('services.zoho.foreign_cron_minutes', []);

        if (in_array($minute, $taken, true)) {
            throw new RuntimeException(sprintf(
                'Minute :%02d belongs to the expense tracker (its minutes: %s). The Analytics '
                .'export concurrency limit is ACCOUNT-WIDE, not per application, so scheduling '
                .'here would compete for the same slots and can break both apps — it caused a '
                .'two-day stall once. Pick another minute AND agree it with Tushar.',
                $minute,
                implode(', ', array_map(fn ($m) => sprintf(':%02d', $m), $taken)),
            ));
        }
    }
}
