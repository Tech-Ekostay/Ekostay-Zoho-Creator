<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Closes the CA_Master <-> COA cycle described in 100180. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            $table->foreignId('bank_coa_account_id')->nullable()->after('email')
                ->constrained('coa_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_coa_account_id');
        });
    }
};
