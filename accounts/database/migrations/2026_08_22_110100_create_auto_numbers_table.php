<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `Auto_Numbers` — the payment-number counter (§7.2, §7.6).
 *
 * A SINGLETON in Creator, fetched as `Auto_Numbers[ID != null]` with no ordering
 * (Accounts.ds:45400). If a second row ever existed the read would be arbitrary,
 * which is the same latent bug the handoff records for Block_Payment. Here the
 * singleton is enforced: `singleton` is a constant true with a unique index, so a
 * second row cannot be inserted at all.
 *
 * Counters are BIGINT. `Payment No` is already at 20938 and `Haewaya No` at 32010
 * in the live export — nothing narrower is safe long-term, and §15.2's float
 * corruption is the standing reminder about numeric width in this app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();

            // Enforced singleton — see docblock.
            $table->boolean('singleton')->default(true);

            $table->text('payment_series')->nullable();          // "EKS/PY"
            $table->unsignedBigInteger('payment_no')->default(1);
            $table->text('books_payment_series')->nullable();    // "EKS/BPY"
            $table->unsignedBigInteger('books_payment_no')->default(1);
            $table->text('haewaya_series')->nullable();          // "EKS/Haewaya"
            $table->unsignedBigInteger('haewaya_no')->default(1);

            $table->timestamps();

            $table->unique('singleton');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_numbers');
    }
};
