<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = Schema::hasTable('cutinapp_event_posts')
            ? 'cutinapp_event_posts'
            : (Schema::hasTable('event_posts') ? 'event_posts' : null);

        if (! $tableName || ! Schema::hasColumn($tableName, 'event_id')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY `event_id` BIGINT UNSIGNED NULL',
                str_replace('`', '``', $tableName)
            ));
        } else {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('event_id')->nullable()->change();
            });
        }

        if (! $this->hasIndex($tableName, 'social_feed_posts_idx')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->index(
                    ['app_id', 'event_id', 'parent_id', 'status', 'created_at'],
                    'social_feed_posts_idx'
                );
            });
        }
    }

    public function down(): void
    {
        $tableName = Schema::hasTable('cutinapp_event_posts')
            ? 'cutinapp_event_posts'
            : (Schema::hasTable('event_posts') ? 'event_posts' : null);

        if (! $tableName) {
            return;
        }

        if ($this->hasIndex($tableName, 'social_feed_posts_idx')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex('social_feed_posts_idx');
            });
        }

        // Do not force event_id back to NOT NULL when global posts already exist.
        // A rollback must not destroy user-authored feed content.
    }

    private function hasIndex(string $tableName, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('".str_replace("'", "''", $tableName)."')"))
                ->contains(fn ($index) => ($index->name ?? null) === $indexName);
        }

        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $tableName)
            ->where('index_name', $indexName)
            ->exists();
    }
};
