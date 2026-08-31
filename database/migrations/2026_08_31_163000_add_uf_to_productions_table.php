<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('productions') && ! Schema::hasColumn('productions', 'uf')) {
            Schema::table('productions', function (Blueprint $table) {
                $table->string('uf', 2)->nullable()->after('city');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('productions') && Schema::hasColumn('productions', 'uf')) {
            Schema::table('productions', function (Blueprint $table) {
                $table->dropColumn('uf');
            });
        }
    }
};
