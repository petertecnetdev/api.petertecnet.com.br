<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('items') && ! Schema::hasColumn('items', 'deleted_at')) {
            Schema::table('items', function (Blueprint $table) {
                $table->softDeletes()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('items') && Schema::hasColumn('items', 'deleted_at')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
