<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permissions, and the role pivot — §3.3.
 *
 * The full role -> permission matrix is an open [TODO] in §16; these tables give
 * it somewhere to land without guessing its contents. Payment Requests' three
 * views are the first hard evidence (addendum §5): a requester may create and
 * inline-edit their own, an admin may read across everyone but not edit inline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 96)->unique();      // e.g. payments.reverse
            $table->text('name');
            $table->string('module', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
    }
};
