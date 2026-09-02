<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    private const ALIASES = [
        'laora_profiles' => 'connection_profiles',
        'laora_photos' => 'connection_profile_photos',
        'laora_swipes' => 'connection_decisions',
        'laora_matches' => 'connections',
        'laora_messages' => 'connection_messages',
        'laora_blocks' => 'connection_blocks',
        'laora_reports' => 'connection_reports',
        'laora_moderation_actions' => 'connection_moderation_actions',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->materializeForCi();
            return;
        }

        $applicationId = DB::table('applications')
            ->whereRaw('LOWER(slug) = ?', ['laora'])
            ->value('id');

        if (! $applicationId) {
            throw new RuntimeException('Cannot expand connection storage: source application is not registered.');
        }

        // Expand only. Physical legacy names remain intact for old PHP workers.
        // The dynamic DEFAULT ensures a legacy INSERT that does not know app_id
        // is immediately visible to V1 during a rolling deployment.
        foreach (array_keys(self::ALIASES) as $legacy) {
            if (! Schema::hasTable($legacy)) {
                continue;
            }

            $this->addInstantApplicationColumn($legacy, (int) $applicationId);
        }

        $views = [
            'connection_profiles' => 'SELECT * FROM '.$this->quote('laora_profiles'),
            'connection_profile_photos' => 'SELECT * FROM '.$this->quote('laora_photos'),
            'connection_decisions' => 'SELECT `id`, `app_id`, `swiper_user_id` AS `actor_user_id`, `target_user_id`, `action`, `created_at`, `updated_at` FROM `laora_swipes`',
            'connections' => 'SELECT * FROM '.$this->quote('laora_matches'),
            'connection_messages' => 'SELECT `id`, `app_id`, `match_id` AS `connection_id`, `sender_user_id`, `body`, `read_at`, `deleted_at`, `created_at`, `updated_at` FROM `laora_messages`',
            'connection_blocks' => 'SELECT * FROM '.$this->quote('laora_blocks'),
            'connection_reports' => 'SELECT `id`, `app_id`, `reporter_user_id`, `reported_user_id`, `match_id` AS `connection_id`, `reason`, `details`, `status`, `resolved_at`, `resolved_by_user_id`, `created_at`, `updated_at` FROM `laora_reports`',
            'connection_moderation_actions' => 'SELECT * FROM '.$this->quote('laora_moderation_actions'),
        ];

        foreach ($views as $domain => $select) {
            $legacy = array_search($domain, self::ALIASES, true);
            if (! $legacy || ! Schema::hasTable($legacy)) {
                continue;
            }

            if ($this->objectType($domain) === 'BASE TABLE') {
                continue;
            }

            DB::statement('CREATE OR REPLACE ALGORITHM=MERGE VIEW '.$this->quote($domain).' AS '.$select);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->restoreCiLegacyNames();
            return;
        }

        foreach (array_reverse(array_values(self::ALIASES)) as $domain) {
            if ($this->objectType($domain) === 'VIEW') {
                DB::statement('DROP VIEW IF EXISTS '.$this->quote($domain));
            }
        }

        foreach (array_reverse(array_keys(self::ALIASES)) as $legacy) {
            if (Schema::hasTable($legacy) && Schema::hasColumn($legacy, 'app_id')) {
                DB::statement('ALTER TABLE '.$this->quote($legacy).' DROP COLUMN `app_id`, ALGORITHM=INSTANT');
            }
        }
    }

    private function materializeForCi(): void
    {
        foreach (self::ALIASES as $legacy => $domain) {
            if (Schema::hasTable($legacy) && ! Schema::hasTable($domain)) {
                DB::statement('ALTER TABLE '.$this->quote($legacy).' RENAME TO '.$this->quote($domain));
            }
        }

        if (Schema::hasColumn('connection_decisions', 'swiper_user_id')) {
            Schema::table('connection_decisions', fn (Blueprint $table) => $table->renameColumn('swiper_user_id', 'actor_user_id'));
        }
        if (Schema::hasColumn('connection_messages', 'match_id')) {
            Schema::table('connection_messages', fn (Blueprint $table) => $table->renameColumn('match_id', 'connection_id'));
        }
        if (Schema::hasColumn('connection_reports', 'match_id')) {
            Schema::table('connection_reports', fn (Blueprint $table) => $table->renameColumn('match_id', 'connection_id'));
        }

        foreach (array_values(self::ALIASES) as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'app_id')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->unsignedBigInteger('app_id')->nullable()->after('id'));
            }
        }

        $applicationId = DB::table('applications')->whereRaw('LOWER(slug) = ?', ['laora'])->value('id');
        $hasRows = collect(array_values(self::ALIASES))->contains(
            fn (string $table) => Schema::hasTable($table) && DB::table($table)->exists()
        );

        if ($hasRows && ! $applicationId) {
            throw new RuntimeException('Cannot scope existing connection data: source application is not registered.');
        }

        if ($applicationId) {
            foreach (array_values(self::ALIASES) as $tableName) {
                if (Schema::hasTable($tableName)) {
                    DB::table($tableName)->whereNull('app_id')->update(['app_id' => $applicationId]);
                }
            }
        }

        $this->replaceLegacyUniqueIndexesForCi();
        foreach (array_values(self::ALIASES) as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'app_id')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->index('app_id'));
            }
        }
    }

    private function restoreCiLegacyNames(): void
    {
        foreach (array_reverse(array_values(self::ALIASES)) as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'app_id')) {
                try {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex([$tableName === 'connections' ? 'app_id' : 'app_id']));
                } catch (Throwable) {
                    // Rollback compatibility: index naming differs between SQLite versions.
                }
            }
        }

        $this->restoreLegacyUniqueIndexesForCi();

        foreach (array_reverse(array_values(self::ALIASES)) as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'app_id')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('app_id'));
            }
        }

        if (Schema::hasColumn('connection_reports', 'connection_id')) {
            Schema::table('connection_reports', fn (Blueprint $table) => $table->renameColumn('connection_id', 'match_id'));
        }
        if (Schema::hasColumn('connection_messages', 'connection_id')) {
            Schema::table('connection_messages', fn (Blueprint $table) => $table->renameColumn('connection_id', 'match_id'));
        }
        if (Schema::hasColumn('connection_decisions', 'actor_user_id')) {
            Schema::table('connection_decisions', fn (Blueprint $table) => $table->renameColumn('actor_user_id', 'swiper_user_id'));
        }

        foreach (array_reverse(self::ALIASES, true) as $legacy => $domain) {
            if (Schema::hasTable($domain) && ! Schema::hasTable($legacy)) {
                DB::statement('ALTER TABLE '.$this->quote($domain).' RENAME TO '.$this->quote($legacy));
            }
        }
    }

    private function addInstantApplicationColumn(string $table, int $applicationId): void
    {
        if (Schema::hasColumn($table, 'app_id')) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE %s ADD COLUMN `app_id` BIGINT UNSIGNED NULL DEFAULT %d AFTER `id`, ALGORITHM=INSTANT',
            $this->quote($table),
            $applicationId
        ));
    }

    private function objectType(string $name): ?string
    {
        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            throw new RuntimeException('Database name is required to inspect compatibility views.');
        }

        $value = DB::table('information_schema.tables')
            ->where('table_schema', $database)
            ->where('table_name', $name)
            ->value('table_type');

        return $value ? strtoupper((string) $value) : null;
    }

    private function replaceLegacyUniqueIndexesForCi(): void
    {
        if (Schema::hasTable('connection_profiles')) {
            Schema::table('connection_profiles', function (Blueprint $table) {
                $table->dropUnique('laora_profiles_user_id_unique');
                $table->unique(['app_id', 'user_id']);
            });
        }
        if (Schema::hasTable('connection_decisions')) {
            Schema::table('connection_decisions', function (Blueprint $table) {
                $table->dropUnique('laora_swipes_swiper_user_id_target_user_id_unique');
                $table->unique(['app_id', 'actor_user_id', 'target_user_id']);
            });
        }
        if (Schema::hasTable('connections')) {
            Schema::table('connections', function (Blueprint $table) {
                $table->dropUnique('laora_matches_user_one_id_user_two_id_unique');
                $table->unique(['app_id', 'user_one_id', 'user_two_id']);
            });
        }
        if (Schema::hasTable('connection_blocks')) {
            Schema::table('connection_blocks', function (Blueprint $table) {
                $table->dropUnique('laora_blocks_blocker_user_id_blocked_user_id_unique');
                $table->unique(['app_id', 'blocker_user_id', 'blocked_user_id']);
            });
        }
    }

    private function restoreLegacyUniqueIndexesForCi(): void
    {
        if (Schema::hasTable('connection_blocks')) {
            Schema::table('connection_blocks', function (Blueprint $table) {
                $table->dropUnique(['app_id', 'blocker_user_id', 'blocked_user_id']);
                $table->unique(['blocker_user_id', 'blocked_user_id']);
            });
        }
        if (Schema::hasTable('connections')) {
            Schema::table('connections', function (Blueprint $table) {
                $table->dropUnique(['app_id', 'user_one_id', 'user_two_id']);
                $table->unique(['user_one_id', 'user_two_id']);
            });
        }
        if (Schema::hasTable('connection_decisions')) {
            Schema::table('connection_decisions', function (Blueprint $table) {
                $table->dropUnique(['app_id', 'actor_user_id', 'target_user_id']);
                $table->unique(['actor_user_id', 'target_user_id']);
            });
        }
        if (Schema::hasTable('connection_profiles')) {
            Schema::table('connection_profiles', function (Blueprint $table) {
                $table->dropUnique(['app_id', 'user_id']);
                $table->unique('user_id');
            });
        }
    }

    private function quote(string $identifier): string
    {
        return DB::getDriverName() === 'sqlite'
            ? '"'.str_replace('"', '""', $identifier).'"'
            : '`'.str_replace('`', '``', $identifier).'`';
    }
};
