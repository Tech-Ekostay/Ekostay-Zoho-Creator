<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterCategory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['fb' => 'boolean'];
    }

    public function itemCategories(): HasMany
    {
        return $this->hasMany(ItemCategory::class);
    }

    /**
     * §4.2 — F&B scoping is the BOOLEAN, never a string comparison on the name.
     * The expense tracker filtered `master_category == 'F&B'`, which is why
     * BAKERY and KIRANA kept leaking.
     */
    public function scopeFoodAndBeverage(Builder $query): Builder
    {
        return $query->where('fb', true);
    }
}
