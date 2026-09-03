<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class IdentityPreflight extends Command
{
    protected $signature = 'identity:preflight {--production : Enforce production requirements}';

    protected $description = 'Validate Peter Identity storage, schema and security configuration.';

    public function handle(): int
    {
        $production = (bool) $this->option('production') || app()->environment('production');
        $failures = [];
        $warnings = [];

        $cacheStore = (string) config('identity.cache_store', 'redis');
        $this->line('Identity cache store: '.$cacheStore);

        if ($production && $cacheStore !== 'redis') {
            $failures[] = 'IDENTITY_CACHE_STORE deve ser redis em produção.';
        }

        try {
            $key = 'identity:preflight:'.bin2hex(random_bytes(12));
            Cache::store($cacheStore)->put($key, 'ok', now()->addSeconds(30));
            $value = Cache::store($cacheStore)->get($key);
            Cache::store($cacheStore)->forget($key);

            if ($value !== 'ok') {
                $failures[] = "O cache {$cacheStore} não confirmou escrita/leitura.";
            } else {
                $this->info('Identity cache: OK');
            }
        } catch (Throwable $e) {
            $failures[] = "O cache {$cacheStore} não está operacional: {$e->getMessage()}";
        }

        foreach (['identity_devices', 'identity_sessions', 'identity_auth_events'] as $table) {
            if (! Schema::hasTable($table)) {
                $failures[] = "Tabela obrigatória ausente: {$table}.";
            }
        }

        if (! $failures) {
            $this->info('Identity schema: OK');
        }

        if ((int) config('identity.access_token_ttl_minutes', 0) < 5
            || (int) config('identity.access_token_ttl_minutes', 0) > 60) {
            $warnings[] = 'IDENTITY_ACCESS_TOKEN_TTL_MINUTES deve permanecer entre 5 e 60 minutos.';
        }

        if ((int) config('identity.refresh_grace_seconds', 0) < 5
            || (int) config('identity.refresh_grace_seconds', 0) > 120) {
            $warnings[] = 'IDENTITY_REFRESH_GRACE_SECONDS está fora da faixa operacional recomendada (5–120s).';
        }

        if ($production && ! (bool) config('identity.require_https_origin', true)) {
            $failures[] = 'IDENTITY_REQUIRE_HTTPS_ORIGIN deve permanecer habilitado em produção.';
        }

        if ((int) config('identity.audit_retention_days', 0) < 30) {
            $warnings[] = 'Retenção de auditoria inferior a 30 dias reduz a capacidade de investigação.';
        }

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        if ($failures) {
            foreach ($failures as $failure) {
                $this->error($failure);
            }
            $this->error('Peter Identity preflight: FALHOU');
            return self::FAILURE;
        }

        $this->info('Peter Identity preflight: OK');
        return self::SUCCESS;
    }
}
