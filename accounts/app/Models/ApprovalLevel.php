<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One row of the Approval form's Approvers grid: a level, its amount band, its
 * approvers and its Any/All semantics.
 *
 * `minimum_amount` IS NULL ON EVERY SEEDED ROW, and that is a fact about the source
 * rather than an oversight. `All_Approvals` exports the rule headers only — its
 * `Approvers` column is the string "Level 1,Level 2", naming which levels exist and
 * nothing more. `ApprovalRouter` refuses to route while the bands are null instead of
 * reading them as zero, which would silently send everything to one level.
 *
 * `maximum_amount` is captured because the form writes it, and is never read: the
 * routing consults minimums only (Accounts.ds:16066).
 */
class ApprovalLevel extends Model
{
    protected $guarded = [];

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function approvers(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'approval_level_approver');
    }

    /** Whether one approver at this level suffices, or all of them are needed. */
    public function requiresAll(): bool
    {
        return strcasecmp(trim((string) $this->approval_type), 'All') === 0;
    }
}
