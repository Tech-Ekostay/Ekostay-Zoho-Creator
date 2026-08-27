<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles as a first-class table — §3.3, "the highest-value structural fix in the
 * master layer".
 *
 * In Creator, Employee_Master.User_Role is unconstrained TEXT matched with
 * .contains(), with "accounts head" as a lowercase special case. That is what
 * this replaces. §17 step 3 forbids any string .contains() in the authorisation
 * path.
 *
 * source_label preserves the exact Creator string so existing records can be
 * mapped without normalising the data on the way in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->text('name');
            $table->text('source_label')->nullable();  // verbatim Creator User_Role text
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
