<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * admin.Employee_Department — §3.2. Designation is a list on the source form, so
 * it is a pivot here rather than a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_departments', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();
            $table->text('name');                      // source: Department
            $table->timestamps();
        });

        Schema::create('employee_department_designation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_designation_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['employee_department_id', 'employee_designation_id'], 'emp_dept_desig_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_department_designation');
        Schema::dropIfExists('employee_departments');
    }
};
