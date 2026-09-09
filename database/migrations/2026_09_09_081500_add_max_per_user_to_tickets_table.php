<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tickets') && ! Schema::hasColumn('tickets', 'max_per_user')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->unsignedInteger('max_per_user')->nullable()->after('quantity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tickets') && Schema::hasColumn('tickets', 'max_per_user')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropColumn('max_per_user');
            });
        }
    }
};
