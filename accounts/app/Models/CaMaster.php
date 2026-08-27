<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaMaster extends Model
{
    protected $guarded = [];

    public function coaAccounts(): HasMany
    {
        return $this->hasMany(CoaAccount::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(CoaAccount::class, 'bank_coa_account_id');
    }
}
