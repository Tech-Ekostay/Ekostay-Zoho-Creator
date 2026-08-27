<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `Billing_Cycles` — a month/year pair (§3).
 *
 * `year` is TEXT in the source and stays text here. `month_index` is the derived
 * sortable form; ordering on `month_name` would sort April before January.
 */
class BillingCycle extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'month_index' => 'integer',
        ];
    }

    public function label(): string
    {
        return trim($this->month_name.' '.$this->year);
    }
}
