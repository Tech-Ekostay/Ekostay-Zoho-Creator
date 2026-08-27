<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the `Approvers` multi-select — who MAY approve this record.
 *
 * Not the same as `PendingApprovalApprover`, which records who HAS. The Creator edit
 * form shows the two as separate controls: chips for the candidates, a grid for the
 * decisions.
 */
class PendingApprovalCandidate extends Model
{
    protected $guarded = [];

    protected $table = 'pending_approval_candidates';

    public function pendingApproval(): BelongsTo
    {
        return $this->belongsTo(PendingApproval::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
