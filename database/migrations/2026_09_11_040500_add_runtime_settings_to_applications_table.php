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
            if (! Schema::hasColumn('applications', 'runtime_settings')) {
                $table->json('runtime_settings')->nullable()->after('capabilities');
            }
        });

        $kryvionOff = [
            'mode' => 'off',
            'processing_enabled' => false,
            'scheduled_processing_enabled' => false,
            'market_scanner_enabled' => false,
            'ai_enabled' => false,
            'notifications_enabled' => false,
            'emails_enabled' => false,
            'reports_enabled' => false,
            'realtime_enabled' => false,
            'scan_interval_minutes' => 60,
            'idle_timeout_minutes' => 15,
            'reason' => 'Suspenso temporariamente para reduzir consumo da VPS enquanto não há usuários ativos.',
            'updated_by' => null,
            'updated_at' => now()->toIso8601String(),
        ];

        DB::table('applications')
            ->where('slug', 'kryvion')
            ->update(['runtime_settings' => json_encode($kryvionOff, JSON_UNESCAPED_UNICODE)]);
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            if (Schema::hasColumn('applications', 'runtime_settings')) {
                $table->dropColumn('runtime_settings');
            }
        });
    }
};
