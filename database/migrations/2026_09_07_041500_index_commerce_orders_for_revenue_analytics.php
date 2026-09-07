<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'commerce_orders_revenue_analytics_idx';

    public function up(): void
    {
        $table = $this->table();

        if (! $table || ! Schema::hasColumn($table, 'app_id') || ! Schema::hasColumn($table, 'production_id') || ! Schema::hasColumn($table, 'created_at') || ! Schema::hasColumn($table, 'status')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (app_id, production_id, created_at, status)',
                self::INDEX,
                $table
            ));

            return;
        }

        $exists = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, self::INDEX]
        );

        if ((int) ($exists->aggregate ?? 0) === 0) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD INDEX `%s` (`app_id`, `production_id`, `created_at`, `status`), ALGORITHM=INPLACE, LOCK=NONE',
                $table,
                self::INDEX
            ));
        }
    }

    public function down(): void
    {
        $table = $this->table();
        if (! $table) return;

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
            return;
        }

        $exists = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, self::INDEX]
        );

        if ((int) ($exists->aggregate ?? 0) > 0) {
            DB::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`, ALGORITHM=INPLACE, LOCK=NONE', $table, self::INDEX));
        }
    }

    private function table(): ?string
    {
        if (DB::getDriverName() === 'sqlite') {
            return Schema::hasTable('commerce_orders') ? 'commerce_orders' : null;
        }

        // During the rolling-storage compatibility window, commerce_orders is a
        // writable logical view while cutinapp_orders remains the physical table.
        if (Schema::hasTable('cutinapp_orders')) return 'cutinapp_orders';
        if (Schema::hasTable('commerce_orders')) return 'commerce_orders';

        return null;
    }
};
