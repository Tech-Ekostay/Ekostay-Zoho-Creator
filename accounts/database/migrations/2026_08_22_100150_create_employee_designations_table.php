<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** admin.Employee_Designation — §3.2. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_designations', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();
            $table->text('name');                      // source: Designation
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_designations');
    }
};
