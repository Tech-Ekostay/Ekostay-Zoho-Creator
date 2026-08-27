<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §3.3 / §17 step 3 — roles as a first-class table with an FK, replacing
 * `Employee_Master.User_Role`, which is unconstrained text matched with
 * `.contains()`.
 *
 * §17 step 3 requires: "no string .contains() anywhere in the authorisation
 * path". Resolution of a raw Creator role string happens ONCE, at import, in
 * RoleResolver — never at permission-check time.
 */
class Role extends Model
{
    protected $guarded = [];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function hasPermission(string $slug): bool
    {
        return $this->permissions()->where('slug', $slug)->exists();
    }
}
