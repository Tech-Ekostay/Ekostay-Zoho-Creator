<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An approval RULE — §8.2. Scoped by module, location, villa and item category.
 *
 * The scope columns are comma-packed strings because that is how Creator stores and
 * exports multi-selects. Splitting them is a PARSE, not a `split(',')`: the live
 * strings carry inconsistent spacing (` Casa Bella`, ` Casa Elara`), which is the
 * documented source of the leading-space names in §3. `scopeList()` is the one place
 * that knows, and it trims for COMPARISON only — nothing stored is altered.
 */
class Approval extends Model
{
    /*
     * Creator's four platform fields — Added/Modified Time and User. Not app
     * fields: Creator maintains them on every record of every form, and every
     * report can show them. See the trait for why the user half is null until
     * authorisation exists, and why imported stamps are never overwritten.
     */
    use TracksCreatorAudit;

    protected function casts(): array
    {
        return [
            'added_time' => 'datetime',
            'modified_time' => 'datetime',
        ];
    }

    protected $guarded = [];

    public function levels(): HasMany
    {
        return $this->hasMany(ApprovalLevel::class)->orderBy('position');
    }

    /** @return list<string> */
    public function scopeList(?string $packed): array
    {
        if ($packed === null || trim($packed) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $packed)),
            fn (string $v): bool => $v !== '',
        ));
    }

    /** @return list<string> */
    public function itemCategoryList(): array
    {
        return $this->scopeList($this->item_categories);
    }

    /**
     * `Module` is blank on 1 of the 9 live rules. A blank module is treated as
     * matching, because the picklist offers only "Payment" — so a blank is an
     * unset field on a Payment rule, not a rule for some other module.
     */
    public function coversModule(string $module): bool
    {
        $mine = trim((string) $this->module);

        return $mine === '' || strcasecmp($mine, $module) === 0;
    }

    /** An empty scope list means "all", as an unset filter does in Creator. */
    public function coversLocation(?string $location): bool
    {
        $list = $this->scopeList($this->locations);

        if ($list === []) {
            return true;
        }

        return $location !== null && in_array(trim($location), $list, true);
    }

    public function coversItemCategory(?string $itemCategory): bool
    {
        $list = $this->itemCategoryList();

        if ($list === []) {
            return true;
        }

        return $itemCategory !== null && in_array(trim($itemCategory), $list, true);
    }
}
