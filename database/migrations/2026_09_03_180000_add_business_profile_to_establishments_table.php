<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('establishments', 'business_profile')) {
            Schema::table('establishments', function (Blueprint $table) {
                $table->json('business_profile')->nullable()->after('segments');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('establishments', 'business_profile')) {
            Schema::table('establishments', function (Blueprint $table) {
                $table->dropColumn('business_profile');
            });
        }
    }
};
