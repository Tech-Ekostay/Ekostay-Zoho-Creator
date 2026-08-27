<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HeadOffice extends Model
{
    protected $guarded = [];

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
