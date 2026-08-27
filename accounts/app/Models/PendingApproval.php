<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One in-flight approval, sitting at one level.
 *
 * `chain` is the expanded level list, FROZEN when the payment was submitted. Creator
 * recomputes it from the rule on every approval, which means editing a rule
 * mid-flight silently re-decides an approval already under way — the §14 family of
 * defect, where a rule change retroactively re-answers a decision. Storing the chain
 * is a logged DEVIATION and the only place this engine does not reproduce Creator.
 */
class PendingApproval extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'chain' => 'array',
            'next_level_approval_required' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function isOpen(): bool
    {
        return $this->next_level_approval_required === true;
    }
}
