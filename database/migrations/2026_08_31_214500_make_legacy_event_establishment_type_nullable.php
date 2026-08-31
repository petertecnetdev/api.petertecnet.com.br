<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('events') && Schema::hasColumn('events', 'establishment_type')) {
            Schema::table('events', function (Blueprint $table) {
                $table->string('establishment_type', 100)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. Older installations may contain events
        // from applications where establishment_type was historically required.
        // Restoring NOT NULL could make rollback fail or corrupt cross-app data.
    }
};
