<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    private const TABLES = [
        'connection_profiles',
        'connection_profile_photos',
        'connection_decisions',
        'connections',
        'connection_messages',
        'connection_blocks',
        'connection_reports',
        'connection_moderation_actions',
    ];

    public function up(): void
    {
        $renames = [
            'laora_profiles' => 'connection_profiles',
            'laora_photos' => 'connection_profile_photos',
            'laora_swipes' => 'connection_decisions',
            'laora_matches' => 'connections',
            'laora_messages' => 'connection_messages',
            'laora_blocks' => 'connection_blocks',
            'laora_reports' => 'connection_reports',
            'laora_moderation_actions' => 'connection_moderation_actions',
        ];

        foreach ($renames as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
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

        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'app_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unsignedBigInteger('app_id')->nullable()->after('id');
                });
            }
        }

        $applicationId = DB::table('applications')
            ->whereRaw('LOWER(slug) = ?', ['laora'])
            ->value('id');

        $hasLegacyData = collect(self::TABLES)
            ->contains(fn (string $tableName) => Schema::hasTable($tableName) && DB::table($tableName)->whereNull('app_id')->exists());

        if ($hasLegacyData && ! $applicationId) {
            throw new RuntimeException('Cannot scope existing connection data: source application is not registered.');
        }

        if ($applicationId) {
            foreach (self::TABLES as $tableName) {
                if (Schema::hasTable($tableName)) {
                    DB::table($tableName)->whereNull('app_id')->update(['app_id' => $applicationId]);
                }
            }
        }

        $this->replaceLegacyUniqueIndexes();

        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY `app_id` BIGINT UNSIGNED NOT NULL',
                str_replace('`', '``', $tableName)
            ));

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('app_id')->references('id')->on('applications')->cascadeOnDelete();
                $table->index('app_id');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'app_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['app_id']);
                $table->dropIndex(['app_id']);
            });
        }

        $this->restoreLegacyUniqueIndexes();

        foreach (array_reverse(self::TABLES) as $tableName) {
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

        $renames = [
            'connection_moderation_actions' => 'laora_moderation_actions',
            'connection_reports' => 'laora_reports',
            'connection_blocks' => 'laora_blocks',
            'connection_messages' => 'laora_messages',
            'connections' => 'laora_matches',
            'connection_decisions' => 'laora_swipes',
            'connection_profile_photos' => 'laora_photos',
            'connection_profiles' => 'laora_profiles',
        ];

        foreach ($renames as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }

    private function replaceLegacyUniqueIndexes(): void
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

    private function restoreLegacyUniqueIndexes(): void
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
};
