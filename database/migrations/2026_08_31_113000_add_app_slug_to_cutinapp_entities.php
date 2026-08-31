<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['productions', 'events', 'tickets'] as $table) {
            if (! Schema::hasColumn($table, 'app_slug')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->string('app_slug', 64)->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['tickets', 'events', 'productions'] as $table) {
            if (Schema::hasColumn($table, 'app_slug')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('app_slug');
                });
            }
        }
    }
};
