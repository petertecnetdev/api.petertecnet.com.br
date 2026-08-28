<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'auth_version')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedInteger('auth_version')->default(1)->after('password')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'auth_version')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['auth_version']);
                $table->dropColumn('auth_version');
            });
        }
    }
};
