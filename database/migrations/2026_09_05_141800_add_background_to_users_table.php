<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'background')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('background')->nullable()->after('avatar');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'background')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('background');
            });
        }
    }
};
