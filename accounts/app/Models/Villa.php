<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The central entity (§3.1).
 *
 * THREE OVERLAPPING ACTIVE FLAGS — `active`, `status` and `hide_from_payments`.
 * Bills and Payments filter on hide_from_payments, so that is the load-bearing
 * one. `status` is not yet populated: the report export does not carry it.
 */
class Villa extends Model
{
    protected $guarded = [];

    /** The full rent_type domain. Narrowing it is the §3.1 bug. */
    public const RENT_TYPES = [
        'Revenue Split EKOSTAY',
        'Expense Split EKOSTAY',
        'Revenue Share',
        'Lease',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'hide_from_payments' => 'boolean',
            'is_primary' => 'boolean',
            'inner_circle' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function primaryVilla(): BelongsTo
    {
        return $this->belongsTo(self::class, 'primary_villa_id');
    }

    /** §6.2 — the Bills villa picker is `admin.Villa[Hide_From_Payments == false]`. */
    public function scopeSelectableForPayments(Builder $query): Builder
    {
        return $query->where('hide_from_payments', false);
    }

    /**
     * `Haewaya_ID` is a comma-packed list, not an id. Irregular: leading tabs,
     * trailing commas, a space after one comma. Split, drop empties, trim
     * whitespace including tabs — never a bare split(',').
     *
     * @return list<string>
     */
    public function haewayaIds(): array
    {
        if ($this->haewaya_id === null || trim($this->haewaya_id) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $this->haewaya_id)),
            static fn (string $id): bool => $id !== ''
        ));
    }
}
