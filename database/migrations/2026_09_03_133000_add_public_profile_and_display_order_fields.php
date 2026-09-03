<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            if (! Schema::hasColumn('establishments', 'profile_settings')) {
                $table->json('profile_settings')->nullable()->after('segments');
            }
        });

        Schema::table('items', function (Blueprint $table) {
            if (! Schema::hasColumn('items', 'display_order')) {
                $table->unsignedInteger('display_order')->default(0)->after('is_featured')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (Schema::hasColumn('items', 'display_order')) {
                $table->dropColumn('display_order');
            }
        });

        Schema::table('establishments', function (Blueprint $table) {
            if (Schema::hasColumn('establishments', 'profile_settings')) {
                $table->dropColumn('profile_settings');
            }
        });
    }
};
