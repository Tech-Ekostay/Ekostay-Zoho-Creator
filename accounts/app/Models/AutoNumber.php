<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `Auto_Numbers` — the enforced singleton behind payment numbering (§7.2, §7.6).
 *
 * Creator reads it as `Auto_Numbers[ID != null]` with no ordering, so a second row
 * would make the read arbitrary. The unique index on `singleton` makes a second
 * row impossible; see App\Domain\Payments\PaymentNumber for the locking.
 *
 * Counters are cast to integer, NOT to anything float-shaped. §15.2 is the
 * standing warning: a float() silently corrupted 18-digit ids in this app.
 */
class AutoNumber extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'singleton' => 'boolean',
            'payment_no' => 'integer',
            'books_payment_no' => 'integer',
            'haewaya_no' => 'integer',
        ];
    }
}
