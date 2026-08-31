<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * The `Approved By` subform — one row per approver per level.
     *
     * Distinct from `candidates`: this records who HAS acted, that lists who MAY.
     * The Creator form shows both, and on the sample record both hold the same single
     * person — which is exactly the case where merging them would look correct and
     * would break the moment a level has two candidates and one of them acts.
     */
    public function approvers(): HasMany
    {
        return $this->hasMany(PendingApprovalApprover::class)->orderBy("position");
    }

    /** `Approvers` — the multi-select of who may approve. */
    public function candidates(): HasMany
    {
        return $this->hasMany(PendingApprovalCandidate::class);
    }

    public function preferredApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, "preferred_approver_id");
    }

    /**
     * Has the CURRENT level been satisfied?
     *
     * `Any` needs one ticked row at this level; `All` needs every row at this level.
     * The sample record reads `Approval Type = Any` with one approver, where the two
     * rules agree — so this distinction is untested against live data and is
     * implemented from the field's own semantics.
     */
    public function currentLevelSatisfied(): bool
    {
        $atLevel = $this->approvers->filter(
            fn ($a) => trim((string) $a->approval_level) === trim((string) $this->approval_level)
        );

        if ($atLevel->isEmpty()) {
            return false;
        }

        return strcasecmp(trim((string) $this->approval_type), "All") === 0
            ? $atLevel->every(fn ($a) => $a->approved === true)
            : $atLevel->contains(fn ($a) => $a->approved === true);
    }

    /**
     * Compare a status without being defeated by casing — the same reason
     * `Payment::statusIs()` exists. `Paid` / `paid` / `PAID` all occur across this
     * app (addendum §8), and this record carries TWO status columns that can disagree.
     */
    public function statusIs(string $candidate): bool
    {
        return strcasecmp(trim((string) $this->status), trim($candidate)) === 0;
    }

    public function isOpen(): bool
    {
        return $this->next_level_approval_required === true;
    }
}
