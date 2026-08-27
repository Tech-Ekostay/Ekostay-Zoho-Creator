<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the `Approved By` subform: an approver, a level, and whether they have
 * ticked it.
 *
 * The index report renders `Approved By` as a single name because it flattens the
 * grid to its first value — Creator's own UI doing the §12 flattening. The edit form
 * shows the real shape, and it is the only shape that can express
 * `Approval Type = All`: with `Any` one ticked row advances the level, with `All`
 * every row must be ticked.
 *
 * `employee_id` is nullable and `approver_name` travels beside it. An approver who
 * has left may have no `employees` row, and §6 is explicit that deleted records
 * vanish while the history referencing them remains. A null FK with a name is an
 * audit trail; a rejected insert is a lost one.
 */
class PendingApprovalApprover extends Model
{
    use TracksCreatorAudit;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'added_time' => 'datetime',
            'modified_time' => 'datetime',
            'approved' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function pendingApproval(): BelongsTo
    {
        return $this->belongsTo(PendingApproval::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The name as filed, falling back to the linked employee. */
    public function displayName(): string
    {
        return $this->approver_name ?? $this->employee?->name ?? '';
    }
}
