<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('establishments')) {
            return;
        }

        Schema::table('establishments', function (Blueprint $table): void {
            if (! Schema::hasColumn('establishments', 'roles')) {
                $table->json('roles')->nullable()->after('type');
            }
        });

        DB::table('establishments')
            ->where('category', 'production')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $legacy = strtolower(trim((string) ($row->type ?? '')));
                    $type = match ($legacy) {
                        'fixed', 'house', 'casa', 'venue' => 'venue',
                        'collective', 'coletivo' => 'collective',
                        'independent', 'independent_producer', 'producer_independent', 'individual' => 'individual',
                        default => 'company',
                    };

                    $roles = match ($legacy) {
                        'fixed', 'house', 'casa', 'venue' => ['venue', 'producer', 'organizer'],
                        'collective', 'coletivo' => ['producer', 'organizer'],
                        'independent', 'independent_producer', 'producer_independent', 'individual' => ['producer', 'organizer'],
                        default => ['producer'],
                    };

                    DB::table('establishments')->where('id', $row->id)->update([
                        'type' => $type,
                        'roles' => json_encode($roles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => $row->updated_at ?: now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('establishments')) {
            return;
        }

        DB::table('establishments')
            ->where('category', 'production')
            ->whereIn('type', ['company', 'venue', 'collective', 'individual'])
            ->update([
                'type' => DB::raw("CASE WHEN type = 'venue' THEN 'fixed' WHEN type = 'individual' THEN 'independent' ELSE 'production' END"),
            ]);

        Schema::table('establishments', function (Blueprint $table): void {
            if (Schema::hasColumn('establishments', 'roles')) {
                $table->dropColumn('roles');
            }
        });
    }
};
