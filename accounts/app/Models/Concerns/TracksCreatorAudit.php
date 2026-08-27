<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Access\CurrentUser;

/**
 * Maintains Creator's four platform fields: `Added Time`, `Added User`,
 * `Modified Time`, `Modified User`.
 *
 * These are not app fields. Creator sets them on every record of every form, and
 * every report can display them — so a model that skips them is not a faithful
 * record, it is a partial one.
 *
 * ---------------------------------------------------------------------------
 * THE SEMANTICS, taken from the imported data rather than assumed:
 *
 *   ADDED TIME / USER are written once, on insert, and never touched again. An
 *   update must not move them — that is the whole point of having a separate
 *   `modified_*` pair.
 *
 *   MODIFIED TIME / USER are written on insert TOO, not only on update. The first
 *   imported vendor reads `Added Time 22-Aug-2026 18:44:32` and `Modified Time
 *   22-Aug-2026 18:44:33` — one second apart on a record nobody had edited. So
 *   Creator stamps both at creation and then moves only the modified pair. A model
 *   that left `modified_time` null until the first edit would disagree with all
 *   8,063 imported vendors.
 *
 * IMPORTED VALUES ARE NEVER OVERWRITTEN. If an importer already set `added_time`
 * from the source export, that is real Creator history and this trait leaves it
 * alone. Only an absent value is filled. Without that check, re-running
 * `zoho:import-vendors` would stamp 8,063 records with today's date and lose the
 * audit trail the export exists to preserve.
 *
 * ---------------------------------------------------------------------------
 * THE USER IS RESOLVED THROUGH THE CONTAINER, and will be NULL for now.
 *
 * Creator fills these from `zoho.loginuser`. There is no session here — §3.3's
 * authorisation matrix is extracted and tested but not wired to a gate — so there is
 * no user to record and inventing one would be worse than a null. `CurrentUser` is a
 * single seam: bind it once when auth lands and every model starts recording the
 * actor, with no model touched.
 *
 * The imported data shows what these look like when populated — `murali.zoho186`,
 * `mansi.p`, `shibli_ekostayhospitality` — so the column is a login string, not a
 * foreign key. Kept as text to match, and because a Creator login may name someone
 * who has no row in `employees`.
 */
trait TracksCreatorAudit
{
    public static function bootTracksCreatorAudit(): void
    {
        static::creating(function ($model): void {
            $now = now();
            $user = app(CurrentUser::class)->login();

            // Only fill what an importer has not already set from the source.
            foreach (['added_time' => $now, 'modified_time' => $now] as $column => $value) {
                if ($model->{$column} === null) {
                    $model->{$column} = $value;
                }
            }

            foreach (['added_user', 'modified_user'] as $column) {
                if ($model->{$column} === null) {
                    $model->{$column} = $user;
                }
            }
        });

        static::updating(function ($model): void {
            /*
             * The modified pair moves; the added pair does not. And if a caller has
             * explicitly set `modified_time` on this save — an importer replaying
             * Creator history — that value wins, because it is the truth about when
             * the record actually changed.
             */
            if (! $model->isDirty('modified_time')) {
                $model->modified_time = now();
            }

            if (! $model->isDirty('modified_user')) {
                $model->modified_user = app(CurrentUser::class)->login();
            }
        });
    }
}
