<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * admin.Employee_Master — §3.2 / §3.3.
 *
 * This is the identity provider for the whole Creator suite, so it is the table
 * authorisation hangs off. role_id replaces the free-text User_Role; the original
 * string is kept in user_role_source for mapping only and must never be matched
 * on (§17 step 3).
 *
 * Status in Creator is {Active, Inactive}; on any non-Active value the
 * DeleteAccess mirror runs, so treat active as the load-bearing flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();
            $table->text('name');
            $table->text('employee_code')->nullable();  // source: Employee_ID
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            $table->text('address')->nullable();
            $table->date('dob')->nullable();
            $table->date('joining_date')->nullable();
            $table->foreignId('employee_department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_designation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->text('user_role_source')->nullable(); // verbatim Creator text; mapping only
            $table->string('status', 32)->nullable();     // {Active, Inactive}
            $table->boolean('access_given')->default(false);
            $table->boolean('is_hr')->default(false);
            $table->string('ekostay_id', 24)->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
