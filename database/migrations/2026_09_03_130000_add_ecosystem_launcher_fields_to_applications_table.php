<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedInteger('launcher_order')->default(100)->after('self_service_access');
            $table->string('category', 100)->nullable()->after('launcher_order');
            $table->boolean('is_visible')->default(true)->after('category');
            $table->string('operational_status', 30)->default('operational')->after('is_visible');
            $table->string('maintenance_message', 500)->nullable()->after('operational_status');
            $table->string('ecosystem_sdk_version', 30)->nullable()->after('maintenance_message');
        });

        DB::table('applications')->update([
            'is_visible' => true,
            'operational_status' => 'operational',
            'ecosystem_sdk_version' => '2.0.0',
        ]);
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'launcher_order',
                'category',
                'is_visible',
                'operational_status',
                'maintenance_message',
                'ecosystem_sdk_version',
            ]);
        });
    }
};
