<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * admin.Employee_Master — the identity provider for the whole Creator suite
 * (§3.3), and therefore the table authorisation hangs off.
 *
 * `user_role_source` keeps the verbatim Creator string for traceability. It must
 * NEVER be matched on: `role_id` is the authorisation input.
 *
 * Two facts from the live data that shape this model (addendum §14):
 *  - 189 of 475 records are per-villa SERVICE ACCOUNTS (pinewood@ekostay.com and
 *    the like), not people. `isServiceAccount()` separates them.
 *  - `status` has a third state: 2 records are blank. `Access.Accounts` runs the
 *    DeleteAccess mirror on `Status != "Active"`, so blank silently revokes.
 */
class Employee extends Model
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
            'access_given' => 'boolean',
            'is_hr' => 'boolean',
            'dob' => 'date',
            'joining_date' => 'date',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Only `Active` counts. Blank is not active — see the class docblock. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'Active');
    }

    public function isActive(): bool
    {
        return $this->status === 'Active';
    }

    /** A villa mailbox rather than a person. */
    public function isServiceAccount(): bool
    {
        return $this->role?->slug === 'caretaker';
    }

    /**
     * The only permission check. No string matching on user_role_source, ever.
     */
    public function can(string $permission, ?array $arguments = null): bool
    {
        if (! $this->isActive() || $this->role === null) {
            return false;
        }

        return $this->role->hasPermission($permission);
    }
}
