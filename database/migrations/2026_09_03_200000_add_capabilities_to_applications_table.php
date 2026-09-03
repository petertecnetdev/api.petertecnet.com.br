<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            if (! Schema::hasColumn('applications', 'capabilities')) {
                $table->json('capabilities')->nullable()->after('self_service_access');
            }
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            if (Schema::hasColumn('applications', 'capabilities')) {
                $table->dropColumn('capabilities');
            }
        });
    }
};
