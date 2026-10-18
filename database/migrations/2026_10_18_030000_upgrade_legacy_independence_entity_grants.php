<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upgrade additive for installations that ran the first version of
     * 2026_10_17_180000 before active_entity_key existed. Never rewrite
     * historical grants or remove the legacy uniqueness guard prematurely.
     */
    public function up(): void
    {
        $table = 'independence_entity_grants';

        if (! Schema::hasTable($table)) {
            throw new RuntimeException('A tabela de concessões Independence não existe; aplique a migration base primeiro.');
        }

        // Inspect before modifying the schema: inconsistent active grants must
        // be reconciled manually instead of silently assigning ownership.
        $duplicate = DB::table($table)
            ->select('app_id', 'entity_type', 'entity_id')
            ->whereNull('revoked_at')
            ->groupBy('app_id', 'entity_type', 'entity_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException('Há concessões ativas duplicadas; revise a titularidade antes do upgrade.');
        }

        if (! Schema::hasColumn($table, 'active_entity_key')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('active_entity_key', 128)->nullable();
            });
        }

        // A revoked grant must never occupy a unique active-ownership key.
        DB::table($table)->whereNotNull('revoked_at')
            ->whereNotNull('active_entity_key')
            ->update(['active_entity_key' => null]);

        DB::table($table)->whereNull('revoked_at')
            ->orderBy('id')
            ->chunkById(250, function ($grants) use ($table): void {
                foreach ($grants as $grant) {
                    $key = $grant->app_id.':'.$grant->entity_type.':'.$grant->entity_id;

                    if ($grant->active_entity_key !== null
                        && $grant->active_entity_key !== $key) {
                        throw new RuntimeException('Concessão ativa com chave incompatível: '.$grant->id);
                    }

                    if ($grant->active_entity_key === null) {
                        DB::table($table)->where('id', $grant->id)
                            ->whereNull('revoked_at')
                            ->update(['active_entity_key' => $key]);
                    }
                }
            });

        // Establish the replacement uniqueness constraint BEFORE removing the
        // old one, so simultaneous approvals cannot produce duplicate owners.
        if (! Schema::hasIndex($table, 'independence_one_active_owner')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unique('active_entity_key', 'independence_one_active_owner');
            });
        }

        if (Schema::hasIndex($table, 'independence_one_owner_per_entity')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropUnique('independence_one_owner_per_entity');
            });
        }
    }

    public function down(): void
    {
        // Intentionally no destructive rollback: historical revoked grants
        // and current permissions must be preserved for manual review.
    }
};
