<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    private const ALIASES = [
        'cutinapp_artists' => 'artists',
        'cutinapp_event_artist' => 'event_artist',
        'cutinapp_follows' => 'follows',
        'cutinapp_event_engagements' => 'event_engagements',
        'cutinapp_user_preferences' => 'application_user_preferences',
        'cutinapp_artist_members' => 'artist_members',
        'cutinapp_artist_claims' => 'artist_claims',
        'cutinapp_event_ratings' => 'event_ratings',
        'cutinapp_event_reports' => 'event_reports',
        'cutinapp_event_posts' => 'event_posts',
        'cutinapp_event_post_likes' => 'event_post_likes',
        'cutinapp_event_items' => 'event_items',
        'cutinapp_producer_payment_accounts' => 'merchant_payment_accounts',
        'cutinapp_orders' => 'commerce_orders',
        'cutinapp_order_items' => 'commerce_order_items',
        'cutinapp_inventory_reservations' => 'inventory_reservations',
        'cutinapp_payments' => 'commerce_payments',
        'cutinapp_ledger_entries' => 'ledger_entries',
        'cutinapp_payout_requests' => 'payout_requests',
        'cutinapp_producer_contract_acceptances' => 'contract_acceptances',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->materializeForCi();
            return;
        }

        $applicationId = DB::table('applications')
            ->whereRaw('LOWER(slug) = ?', ['cutinapp'])
            ->value('id');

        if (! $applicationId) {
            throw new RuntimeException('Cannot expand event commerce storage: source application is not registered.');
        }

        // Expand-only production migration. Old physical names stay online;
        // generic names are writable views over the exact same rows.
        foreach (array_keys(self::ALIASES) as $legacy) {
            if (! Schema::hasTable($legacy)) {
                continue;
            }

            $this->addInstantApplicationColumn($legacy, (int) $applicationId);
        }

        foreach (self::ALIASES as $legacy => $domain) {
            if (! Schema::hasTable($legacy) || $this->objectType($domain) === 'BASE TABLE') {
                continue;
            }

            DB::statement(
                'CREATE OR REPLACE ALGORITHM=MERGE VIEW '.$this->quote($domain)
                .' AS SELECT * FROM '.$this->quote($legacy)
            );
        }

        $this->expandEventPassOrderItemLink();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->restoreCiLegacyNames();
            return;
        }

        $this->dropEventPassSyncTriggers();

        foreach (array_reverse(array_values(self::ALIASES)) as $domain) {
            if ($this->objectType($domain) === 'VIEW') {
                DB::statement('DROP VIEW IF EXISTS '.$this->quote($domain));
            }
        }

        // Deliberately leave additive app_id / commerce_order_item_id columns in
        // place on rollback. Old code ignores them, and removing live columns
        // would turn a safe rollback into a blocking/destructive migration.
    }

    private function materializeForCi(): void
    {
        foreach (self::ALIASES as $legacy => $domain) {
            if (Schema::hasTable($legacy) && ! Schema::hasTable($domain)) {
                DB::statement('ALTER TABLE '.$this->quote($legacy).' RENAME TO '.$this->quote($domain));
            }
        }

        if (
            Schema::hasTable('event_passes')
            && Schema::hasColumn('event_passes', 'cutinapp_order_item_id')
            && ! Schema::hasColumn('event_passes', 'commerce_order_item_id')
        ) {
            Schema::table('event_passes', fn (Blueprint $table) => $table->renameColumn('cutinapp_order_item_id', 'commerce_order_item_id'));
        }

        if (Schema::hasTable('merchant_payment_accounts') && ! Schema::hasColumn('merchant_payment_accounts', 'app_id')) {
            Schema::table('merchant_payment_accounts', fn (Blueprint $table) => $table->unsignedBigInteger('app_id')->nullable()->after('id'));
        }
    }

    private function restoreCiLegacyNames(): void
    {
        if (
            Schema::hasTable('event_passes')
            && Schema::hasColumn('event_passes', 'commerce_order_item_id')
            && ! Schema::hasColumn('event_passes', 'cutinapp_order_item_id')
        ) {
            Schema::table('event_passes', fn (Blueprint $table) => $table->renameColumn('commerce_order_item_id', 'cutinapp_order_item_id'));
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

    private function expandEventPassOrderItemLink(): void
    {
        if (! Schema::hasTable('event_passes') || ! Schema::hasColumn('event_passes', 'cutinapp_order_item_id')) {
            return;
        }

        if (! Schema::hasColumn('event_passes', 'commerce_order_item_id')) {
            DB::statement(
                'ALTER TABLE `event_passes` ADD COLUMN `commerce_order_item_id` BIGINT UNSIGNED NULL AFTER `cutinapp_order_item_id`, ALGORITHM=INSTANT'
            );
        }

        DB::table('event_passes')
            ->whereNull('commerce_order_item_id')
            ->whereNotNull('cutinapp_order_item_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('event_passes')->where('id', $row->id)->update([
                        'commerce_order_item_id' => $row->cutinapp_order_item_id,
                    ]);
                }
            });

        $this->dropEventPassSyncTriggers();

        DB::unprepared(<<<'SQL'
CREATE TRIGGER `compat_event_passes_order_item_bi`
BEFORE INSERT ON `event_passes`
FOR EACH ROW
BEGIN
    IF NEW.`commerce_order_item_id` IS NULL THEN
        SET NEW.`commerce_order_item_id` = NEW.`cutinapp_order_item_id`;
    END IF;
    IF NEW.`cutinapp_order_item_id` IS NULL THEN
        SET NEW.`cutinapp_order_item_id` = NEW.`commerce_order_item_id`;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER `compat_event_passes_order_item_bu`
BEFORE UPDATE ON `event_passes`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`commerce_order_item_id` <=> OLD.`commerce_order_item_id`)
       AND (NEW.`cutinapp_order_item_id` <=> OLD.`cutinapp_order_item_id`) THEN
        SET NEW.`cutinapp_order_item_id` = NEW.`commerce_order_item_id`;
    ELSEIF NOT (NEW.`cutinapp_order_item_id` <=> OLD.`cutinapp_order_item_id`)
       AND (NEW.`commerce_order_item_id` <=> OLD.`commerce_order_item_id`) THEN
        SET NEW.`commerce_order_item_id` = NEW.`cutinapp_order_item_id`;
    END IF;
END
SQL);
    }

    private function dropEventPassSyncTriggers(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `compat_event_passes_order_item_bi`');
        DB::statement('DROP TRIGGER IF EXISTS `compat_event_passes_order_item_bu`');
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

    private function quote(string $identifier): string
    {
        return DB::getDriverName() === 'sqlite'
            ? '"'.str_replace('"', '""', $identifier).'"'
            : '`'.str_replace('`', '``', $identifier).'`';
    }
};
