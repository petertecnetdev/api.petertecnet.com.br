<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $application = DB::table('applications')->where('slug', 'cutinapp')->first();

        if ($application) {
            DB::table('applications')->where('id', $application->id)->update([
                'name' => 'Cutinapp',
                'description' => 'Plataforma Peter Tecnet para criação de eventos, distribuição de ingressos e validação de entradas por QR Code.',
                'url' => 'https://cutinapp.petertecnet.com.br',
                'is_active' => true,
                'author' => 'Peter Tecnet',
                'updated_at' => $now,
            ]);
            $appId = (int) $application->id;
        } else {
            $appId = (int) DB::table('applications')->insertGetId([
                'name' => 'Cutinapp',
                'description' => 'Plataforma Peter Tecnet para criação de eventos, distribuição de ingressos e validação de entradas por QR Code.',
                'slug' => 'cutinapp',
                'url' => 'https://cutinapp.petertecnet.com.br',
                'logo' => 'https://cutinapp.petertecnet.com.br/images/logo.png',
                'is_active' => true,
                'version' => '1.0.0',
                'author' => 'Peter Tecnet',
                'release_date' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (['productions', 'events', 'tickets'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'app_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->foreignId('app_id')
                        ->nullable()
                        ->after('id')
                        ->constrained('applications')
                        ->nullOnDelete();
                });
            }
        }

        if (Schema::hasTable('productions') && Schema::hasColumn('productions', 'app_id')) {
            DB::table('productions')->where('app_slug', 'cutinapp')->whereNull('app_id')->update(['app_id' => $appId]);
        }

        if (Schema::hasTable('events') && Schema::hasColumn('events', 'app_id')) {
            DB::table('events')->where('app_slug', 'cutinapp')->whereNull('app_id')->update(['app_id' => $appId]);
        }

        if (Schema::hasTable('tickets') && Schema::hasColumn('tickets', 'app_id')) {
            DB::table('tickets')->where('app_slug', 'cutinapp')->whereNull('app_id')->update(['app_id' => $appId]);
        }

        if (Schema::hasTable('productions') && Schema::hasColumn('productions', 'app_id')) {
            Schema::table('productions', fn (Blueprint $table) => $table->index(['app_id', 'user_id'], 'productions_app_user_idx'));
        }
        if (Schema::hasTable('events') && Schema::hasColumn('events', 'app_id')) {
            Schema::table('events', fn (Blueprint $table) => $table->index(['app_id', 'production_id'], 'events_app_production_idx'));
        }
        if (Schema::hasTable('tickets') && Schema::hasColumn('tickets', 'app_id')) {
            Schema::table('tickets', fn (Blueprint $table) => $table->index(['app_id', 'event_id'], 'tickets_app_event_idx'));
        }
    }

    public function down(): void
    {
        foreach ([
            'tickets' => 'tickets_app_event_idx',
            'events' => 'events_app_production_idx',
            'productions' => 'productions_app_user_idx',
        ] as $table => $index) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'app_id')) {
                Schema::table($table, function (Blueprint $blueprint) use ($index) {
                    try {
                        $blueprint->dropIndex($index);
                    } catch (\Throwable) {
                    }
                    try {
                        $blueprint->dropConstrainedForeignId('app_id');
                    } catch (\Throwable) {
                        $blueprint->dropColumn('app_id');
                    }
                });
            }
        }
    }
};
