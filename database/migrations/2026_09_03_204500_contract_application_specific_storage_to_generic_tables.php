<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Physical storage aliases introduced while the generic API was rolled out.
     *
     * The expand migrations deliberately kept the old application-prefixed base
     * tables online and exposed the generic names as writable views. This
     * migration is the contract phase: the generic names become the physical
     * source of truth and the application-prefixed database objects disappear.
     */
    private const TABLE_ALIASES = [
        // Connection domain.
        'laora_profiles' => 'connection_profiles',
        'laora_photos' => 'connection_profile_photos',
        'laora_swipes' => 'connection_decisions',
        'laora_matches' => 'connections',
        'laora_messages' => 'connection_messages',
        'laora_blocks' => 'connection_blocks',
        'laora_reports' => 'connection_reports',
        'laora_moderation_actions' => 'connection_moderation_actions',

        // CRM domain.
        'payflow_contacts' => 'crm_contacts',
        'payflow_opportunities' => 'crm_opportunities',
        'payflow_proposals' => 'crm_proposals',
        'payflow_charges' => 'crm_charges',
        'payflow_agent_activities' => 'crm_agent_activities',

        // Event / social / commerce / finance domains.
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

    private const PRODUCT_PREFIXES = [
        'cutinapp',
        'rasoio',
        'nexus',
        'plat',
        'laora',
        'payflow',
        'inkap',
        'camquick',
    ];

    public function up(): void
    {
        // SQLite clean installs already materialize the generic names in the
        // earlier expand migrations. Keep this migration idempotent there.
        if (DB::getDriverName() === 'sqlite') {
            $this->normalizeGenericColumns();
            $this->assertNoApplicationPrefixedTablesForPortableDatabase();
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Generic storage contraction currently supports MySQL and SQLite only.');
        }

        foreach (self::TABLE_ALIASES as $legacy => $generic) {
            $this->contractTable($legacy, $generic);
        }

        $this->normalizeGenericColumns();
        $this->finalizeEventPassOrderItemLink();
        $this->assertNoApplicationSpecificPhysicalStorage();
    }

    public function down(): void
    {
        // This is an intentionally irreversible contract migration. Recreating
        // application-specific physical storage would reintroduce the exact
        // architectural coupling this migration removes. Rollback is performed
        // by restoring a database backup, not by rebuilding legacy tables.
    }

    private function contractTable(string $legacy, string $generic): void
    {
        $legacyType = $this->objectType($legacy);
        if ($legacyType === null) {
            return;
        }

        // A leftover compatibility view under an application name is not a
        // canonical storage object either.
        if ($legacyType === 'VIEW') {
            DB::statement('DROP VIEW IF EXISTS '.$this->quote($legacy));
            return;
        }

        if ($legacyType !== 'BASE TABLE') {
            throw new RuntimeException("Unsupported database object type for {$legacy}: {$legacyType}.");
        }

        $genericType = $this->objectType($generic);

        if ($genericType === 'VIEW') {
            DB::statement('DROP VIEW IF EXISTS '.$this->quote($generic));
            $genericType = null;
        }

        if ($genericType === 'BASE TABLE') {
            throw new RuntimeException(
                "Cannot contract {$legacy} to {$generic}: both names are physical tables. "
                .'Refusing to guess a destructive merge.'
            );
        }

        if ($genericType !== null) {
            throw new RuntimeException("Unsupported target database object type for {$generic}: {$genericType}.");
        }

        DB::statement(
            'RENAME TABLE '.$this->quote($legacy).' TO '.$this->quote($generic)
        );
    }

    private function normalizeGenericColumns(): void
    {
        $this->renameColumnIfNeeded('connection_decisions', 'swiper_user_id', 'actor_user_id');
        $this->renameColumnIfNeeded('connection_messages', 'match_id', 'connection_id');
        $this->renameColumnIfNeeded('connection_reports', 'match_id', 'connection_id');
    }

    private function renameColumnIfNeeded(string $tableName, string $legacyColumn, string $genericColumn): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (Schema::hasColumn($tableName, $legacyColumn) && ! Schema::hasColumn($tableName, $genericColumn)) {
            Schema::table($tableName, fn (Blueprint $table) => $table->renameColumn($legacyColumn, $genericColumn));
        }
    }

    private function finalizeEventPassOrderItemLink(): void
    {
        if (! Schema::hasTable('event_passes')) {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS `compat_event_passes_order_item_bi`');
        DB::statement('DROP TRIGGER IF EXISTS `compat_event_passes_order_item_bu`');

        $hasLegacy = Schema::hasColumn('event_passes', 'cutinapp_order_item_id');
        $hasGeneric = Schema::hasColumn('event_passes', 'commerce_order_item_id');

        if ($hasLegacy && ! $hasGeneric) {
            Schema::table('event_passes', fn (Blueprint $table) => $table->renameColumn('cutinapp_order_item_id', 'commerce_order_item_id'));
            return;
        }

        if (! $hasLegacy) {
            return;
        }

        DB::table('event_passes')
            ->whereNull('commerce_order_item_id')
            ->whereNotNull('cutinapp_order_item_id')
            ->update(['commerce_order_item_id' => DB::raw('cutinapp_order_item_id')]);

        $this->dropForeignKeysForColumn('event_passes', 'cutinapp_order_item_id');
        $this->dropIndexesForColumn('event_passes', 'cutinapp_order_item_id');

        Schema::table('event_passes', fn (Blueprint $table) => $table->dropColumn('cutinapp_order_item_id'));
    }

    private function dropForeignKeysForColumn(string $tableName, string $columnName): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $database = $this->databaseName();
        $constraints = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $tableName)
            ->where('COLUMN_NAME', $columnName)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->filter()
            ->unique();

        foreach ($constraints as $constraint) {
            DB::statement(
                'ALTER TABLE '.$this->quote($tableName).' DROP FOREIGN KEY '.$this->quote((string) $constraint)
            );
        }
    }

    private function dropIndexesForColumn(string $tableName, string $columnName): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $database = $this->databaseName();
        $indexes = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $tableName)
            ->where('COLUMN_NAME', $columnName)
            ->where('INDEX_NAME', '!=', 'PRIMARY')
            ->pluck('INDEX_NAME')
            ->filter()
            ->unique();

        foreach ($indexes as $index) {
            DB::statement(
                'ALTER TABLE '.$this->quote($tableName).' DROP INDEX '.$this->quote((string) $index)
            );
        }
    }

    private function assertNoApplicationSpecificPhysicalStorage(): void
    {
        $database = $this->databaseName();
        $regexp = '^('.implode('|', array_map(
            static fn (string $prefix) => preg_quote(strtolower($prefix), '/'),
            self::PRODUCT_PREFIXES
        )).')_';

        $tables = DB::table('information_schema.tables')
            ->where('table_schema', $database)
            ->whereRaw('LOWER(table_name) REGEXP ?', [$regexp])
            ->pluck('table_name')
            ->map(fn ($name) => (string) $name)
            ->values()
            ->all();

        $columns = DB::table('information_schema.columns')
            ->where('table_schema', $database)
            ->whereRaw('LOWER(column_name) REGEXP ?', [$regexp])
            ->get(['table_name', 'column_name'])
            ->map(fn ($row) => $row->table_name.'.'.$row->column_name)
            ->values()
            ->all();

        if ($tables !== [] || $columns !== []) {
            throw new RuntimeException(
                'Application-specific physical storage remains after contraction. Objects: '
                .implode(', ', [...$tables, ...$columns])
            );
        }
    }

    private function assertNoApplicationPrefixedTablesForPortableDatabase(): void
    {
        $pattern = '/^('.implode('|', array_map('preg_quote', self::PRODUCT_PREFIXES)).')_/i';
        $tables = DB::connection()->getSchemaBuilder()->getTableListing();
        $violations = array_values(array_filter(
            array_map(fn ($table) => is_object($table) ? (string) ($table->name ?? '') : (string) $table, $tables),
            fn (string $table) => $table !== '' && preg_match($pattern, $table) === 1
        ));

        if ($violations !== []) {
            throw new RuntimeException(
                'Application-specific physical storage remains after contraction: '.implode(', ', $violations)
            );
        }
    }

    private function objectType(string $name): ?string
    {
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::table('sqlite_master')
                ->where('name', $name)
                ->whereIn('type', ['table', 'view'])
                ->first(['type']);

            if (! $row) {
                return null;
            }

            return strtolower((string) $row->type) === 'view' ? 'VIEW' : 'BASE TABLE';
        }

        $value = DB::table('information_schema.tables')
            ->where('table_schema', $this->databaseName())
            ->where('table_name', $name)
            ->value('table_type');

        return $value ? strtoupper((string) $value) : null;
    }

    private function databaseName(): string
    {
        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            throw new RuntimeException('Database name is required to contract generic storage.');
        }

        return $database;
    }

    private function quote(string $identifier): string
    {
        return DB::getDriverName() === 'sqlite'
            ? '"'.str_replace('"', '""', $identifier).'"'
            : '`'.str_replace('`', '``', $identifier).'`';
    }
};
