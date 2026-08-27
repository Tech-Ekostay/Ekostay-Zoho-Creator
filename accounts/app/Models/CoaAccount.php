<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A real typed chart of accounts (§4.1), NOT the VARCHAR enum the expense tracker
 * used. Approval routing branches on account_type (§8.1).
 *
 * `hide` is the boolean displayed as `COA` on screen (addendum §8).
 */
class CoaAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['bank' => 'boolean', 'hide' => 'boolean'];
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class);
    }

    /**
     * ⚠️ This is what the live app offers as bank accounts, and it is wrong in a
     * way worth seeing: 9 of the 44 rows flagged `bank = true` are typed `cash` or
     * `other_current_asset`, including **Security Deposit**. Meanwhile 25 accounts
     * genuinely typed `bank` have the flag false (addendum §3).
     *
     * Reproduced as-is because it is the live filter. Use bankAccountsStrict() for
     * the set a person would actually expect, and see the [TODO] before changing
     * which one the UI calls.
     */
    public function scopeBankAccounts(Builder $query): Builder
    {
        return $query->where('bank', true);
    }

    public function scopeBankAccountsStrict(Builder $query): Builder
    {
        return $query->where('bank', true)->where('account_type', 'bank');
    }
}
