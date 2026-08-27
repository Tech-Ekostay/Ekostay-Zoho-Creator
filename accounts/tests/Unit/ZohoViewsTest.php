<?php

namespace Tests\Unit;

use App\Services\Zoho\ZohoViews;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The Analytics view registry, and the one guard that protects another team.
 *
 * These are the parts of the Zoho integration testable without credentials. The
 * client itself — token refresh, the 8132 backoff, whole-job retry, the two JSON
 * payload shapes, CSV streaming — is NOT covered here and is honestly untested: it
 * needs recorded fixtures from a real export, and no export has been run yet. Said
 * plainly rather than left to look covered by association.
 */
class ZohoViewsTest extends TestCase
{
    #[Test]
    public function every_registered_view_has_an_id_a_workspace_and_a_label(): void
    {
        $this->assertNotEmpty(ZohoViews::all());

        foreach (ZohoViews::all() as $name => $meta) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $name,
                "view key '{$name}' should be a snake_case handle");

            // View ids are 18-digit Creator/Analytics ids and must stay STRINGS.
            // An int literal here would be a precision bug waiting to happen.
            $this->assertIsString($meta['id'], "view '{$name}' id must be a string");
            $this->assertMatchesRegularExpression('/^\d{18}$/', $meta['id'],
                "view '{$name}' id should be an 18-digit id");

            $this->assertContains($meta['workspace'], ['accounts', 'live']);
            $this->assertNotSame('', $meta['label']);
        }
    }

    #[Test]
    public function view_ids_are_unique(): void
    {
        $ids = array_column(ZohoViews::all(), 'id');

        $this->assertSame(count($ids), count(array_unique($ids)),
            'two registry entries point at the same Analytics view');
    }

    #[Test]
    public function both_workspaces_resolve_to_the_documented_ids(): void
    {
        $this->assertSame('443703000000062565', ZohoViews::workspaceId('accounts'));
        $this->assertSame('443703000004950271', ZohoViews::workspaceId('live'));
    }

    #[Test]
    public function an_unconfigured_workspace_is_refused_rather_than_defaulted(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not interchangeable/');

        ZohoViews::workspaceId('nonexistent');
    }

    /**
     * `all_payments` is the trap in the whole registry: it is the obvious view to
     * reach for and it TIMES OUT on bulk export, ending in a held slot. It must stay
     * flagged, and the flag must explain itself.
     */
    #[Test]
    public function all_payments_is_flagged_as_a_view_not_to_export(): void
    {
        $meta = ZohoViews::get('all_payments');

        $this->assertArrayHasKey('avoid', $meta);
        $this->assertStringContainsString('TIMES OUT', $meta['avoid']);
    }

    #[Test]
    public function the_two_very_large_views_are_flagged_for_csv_streaming(): void
    {
        foreach (['bookings', 'booking_payment_type'] as $name) {
            $this->assertTrue(ZohoViews::get($name)['large'] ?? false,
                "'{$name}' must stay flagged large — loading it as JSON OOM'd a server");
        }
    }

    /**
     * An unregistered view is still reachable by raw id — that is how a view gets
     * registered in the first place. It must not be silently rejected.
     */
    #[Test]
    public function a_raw_numeric_view_id_is_accepted(): void
    {
        $meta = ZohoViews::get('443703000009999999');

        $this->assertSame('443703000009999999', $meta['id']);
        $this->assertStringContainsString('unregistered', $meta['label']);
    }

    #[Test]
    public function an_unknown_view_name_lists_what_is_registered(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payment_master/');

        ZohoViews::get('not_a_view');
    }

    /**
     * THE GUARD THAT MATTERS MOST HERE, and the only one with a blast radius outside
     * this application.
     *
     * The Analytics export concurrency limit is account-wide, "not per application",
     * and is shared with the expense tracker's production sync. A collision "will
     * break *both* apps' syncs" and once caused a two-day stall. Its cron minutes are
     * :00, :12, :24, :42, :48 — so this asserts every one of them is refused, because
     * the failure mode is someone adding a schedule months from now having never read
     * the connection guide.
     */
    #[Test]
    public function scheduling_on_the_other_applications_cron_minutes_is_refused(): void
    {
        foreach ([0, 12, 24, 42, 48] as $taken) {
            try {
                ZohoViews::assertScheduleIsClear($taken);
                $this->fail("minute :{$taken} belongs to the expense tracker and was allowed");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('expense tracker', $e->getMessage());
                $this->assertStringContainsString('ACCOUNT-WIDE', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_free_minute_is_allowed(): void
    {
        foreach ([5, 17, 33, 55] as $free) {
            ZohoViews::assertScheduleIsClear($free);
        }

        $this->assertTrue(true, 'no exception for a minute the other app does not use');
    }

    /**
     * The inspection order encodes why we would call Zoho at all: `bills` and
     * `payments` here are 100% fixture, so the payment and expense views are what
     * would put real money in this database. If that order is edited, it should be
     * a deliberate act.
     */
    #[Test]
    public function the_inspection_order_starts_with_the_views_that_carry_real_money(): void
    {
        $order = ZohoViews::inspectionOrder();

        $this->assertSame('payment_master', $order[0]);
        $this->assertSame('expenses', $order[1]);

        foreach ($order as $name) {
            $meta = ZohoViews::get($name);
            $this->assertSame('accounts', $meta['workspace'],
                'the inspection list should stay inside the accounts workspace — `live` is another app\'s domain');
            $this->assertArrayNotHasKey('avoid', $meta,
                "'{$name}' is on the inspection list but flagged as a view not to export");
        }
    }
}
