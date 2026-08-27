<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounts.TDS — §4.
 *
 * Three things the spec gets wrong here, per addendum §1 and §3:
 *
 * 1. There is NO ID column on the TDS report, so creator_id stays null for every
 *    seeded row. books_id is the only external identifier.
 * 2. Status is documented as {Active, Expired}. `Expired` occurs zero times;
 *    19 rows are Active and 16 are BLANK. Blank is the real second state, so the
 *    column is nullable and carries no CHECK.
 * 3. 35 rows hold only 16 distinct name + percentage pairs. `Other Interest than
 *    securities` appears 4x at 10.00, `(Reduced)` 4x at 7.50, and every duplicate
 *    is Active — so the live picker shows one rate several times under different
 *    Books ids and the clerk picks arbitrarily.
 *
 * Therefore NO unique constraint on (name, tds_percentage). Deduping is a
 * deliberate migration with a mapping table, and it is blocked on an open [TODO]:
 * are the extra Books ids live in Books or orphaned? If live, this needs a map,
 * not a delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tds_rates', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique(); // always null: no ID column
            $table->text('name');
            $table->decimal('tds_percentage', 8, 3)->nullable();
            $table->string('books_id', 24)->nullable();
            $table->string('status', 32)->nullable();     // Active | NULL. never 'Expired'
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tds_rates');
    }
};
