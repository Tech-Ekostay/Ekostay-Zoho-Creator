<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
use Illuminate\Database\Eloquent\Model;

/**
 * `Billing_Cycles` — a month/year pair (§3).
 *
 * `year` is TEXT in the source and stays text here. `month_index` is the derived
 * sortable form; ordering on `month_name` would sort April before January.
 */
class BillingCycle extends Model
{
    /*
     * Creator's four platform fields — Added/Modified Time and User. Not app
     * fields: Creator maintains them on every record of every form, and every
     * report can show them. See the trait for why the user half is null until
     * authorisation exists, and why imported stamps are never overwritten.
     */
    use TracksCreatorAudit;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'added_time' => 'datetime',
            'modified_time' => 'datetime',
            'month_index' => 'integer',
        ];
    }

    /**
     * `August - 2026` — space, hyphen, space.
     *
     * CORRECTED 27-Aug-2026 from a screenshot of the live All Expenses report, which
     * renders `August - 2026`. This returned `August 2026` and was wrong about the
     * separator.
     *
     * The separator is not cosmetic. `payment_master` exports cycles in exactly this
     * dashed form and `expenses` exports the abbreviated `Aug 2026`, so a label that
     * matched neither could not be used to look a cycle up from either export. The
     * importers alias all the spellings for that reason; this is the one the UI shows,
     * and it now agrees with the report.
     */
    public function label(): string
    {
        return trim($this->month_name).' - '.trim((string) $this->year);
    }
}
