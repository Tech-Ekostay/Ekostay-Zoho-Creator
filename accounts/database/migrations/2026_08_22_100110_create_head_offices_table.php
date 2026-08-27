<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** admin.Head_Office — §3.2. Locations are the inverse side of locations.head_office_id. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('head_offices', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();
            $table->text('name');                      // source: Head_Office
            $table->text('circle_ids')->nullable();    // packed in source; not parsed yet
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('head_offices');
    }
};
