<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    private const ALIASES = [
        'payflow_contacts' => 'crm_contacts',
        'payflow_opportunities' => 'crm_opportunities',
        'payflow_proposals' => 'crm_proposals',
        'payflow_charges' => 'crm_charges',
        'payflow_agent_activities' => 'crm_agent_activities',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->materializeForCi();
            return;
        }

        // Expand phase for MariaDB/MySQL: keep the physical legacy tables in
        // place so old PHP workers and new V1 workers can coexist. The generic
        // names are simple MERGE views and therefore remain writable.
        foreach (self::ALIASES as $legacy => $domain) {
            if (! Schema::hasTable($legacy)) {
                continue;
            }

            $type = $this->objectType($domain);
            if ($type === 'BASE TABLE') {
                // A later contract migration may already have materialized the
                // generic table. Never replace real data with a view.
                continue;
            }

            $legacySql = $this->quote($legacy);
            $domainSql = $this->quote($domain);
            DB::statement("CREATE OR REPLACE ALGORITHM=MERGE VIEW {$domainSql} AS SELECT * FROM {$legacySql}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (array_reverse(self::ALIASES, true) as $legacy => $domain) {
                if (Schema::hasTable($domain) && ! Schema::hasTable($legacy)) {
                    DB::statement('ALTER TABLE '.$this->quote($domain).' RENAME TO '.$this->quote($legacy));
                }
            }
            return;
        }

        foreach (array_reverse(self::ALIASES, true) as $domain) {
            if ($this->objectType($domain) === 'VIEW') {
                DB::statement('DROP VIEW IF EXISTS '.$this->quote($domain));
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
