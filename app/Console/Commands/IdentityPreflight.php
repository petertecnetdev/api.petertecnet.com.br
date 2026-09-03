<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class IdentityPreflight extends Command
{
    protected $signature = 'identity:preflight {--production : Enforce production requirements}';

    protected $description = 'Validate Peter Identity schema, Redis and security configuration.';

    public function handle(): int
    {
        $production = (bool) $this->option('production') || app()->environment('production');
        $failures = [];
        $warnings = [];
        $store = (string) config('identity.global_sso.cache_store', app()->environment('testing') ? 'array' : 'redis');

        $this->line('Identity SSO cache store: '.$store);
        if ($production && $store !== 'redis') {
            $failures[] = 'IDENTITY_CACHE_STORE deve ser redis em produção.';
        }

        try {
            $key = 'identity:preflight:'.bin2hex(random_bytes(12));
            Cache::store($store)->put($key, 'ok', now()->addSeconds(30));
            $value = Cache::store($store)->get($key);
            Cache::store($store)->forget($key);

            if ($value !== 'ok') {
                $failures[] = "O cache {$store} não confirmou escrita e leitura.";
            } else {
                $this->info('Identity cache: OK');
            }
        } catch (Throwable $e) {
            $failures[] = "O cache {$store} não está operacional: {$e->getMessage()}";
        }

        foreach ([
            'identity_sessions',
            'identity_challenges',
            'identity_credentials',
            'identity_security_settings',
            'identity_global_sessions',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                $failures[] = "Tabela obrigatória ausente: {$table}.";
            }
        }

        if (! array_filter($failures, fn ($failure) => str_contains($failure, 'Tabela obrigatória'))) {
            $this->info('Identity schema: OK');
        }

        $ttl = (int) config('identity.access_token_ttl_minutes', 30);
        if ($ttl < 5 || $ttl > 60) {
            $failures[] = 'IDENTITY_ACCESS_TOKEN_TTL_MINUTES deve ficar entre 5 e 60 minutos.';
        }

        $grace = (int) config('identity.global_sso.refresh_grace_seconds', 30);
        if ($grace < 5 || $grace > 120) {
            $warnings[] = 'IDENTITY_REFRESH_GRACE_SECONDS está fora da faixa recomendada de 5 a 120 segundos.';
        }

        if ($production && ! (bool) config('identity.global_sso.require_https_origin', true)) {
            $failures[] = 'IDENTITY_REQUIRE_HTTPS_ORIGIN deve permanecer habilitado em produção.';
        }

        $origins = (array) config('identity.passkeys.origins', []);
        if ($production && collect($origins)->contains(fn ($origin) => ! str_starts_with((string) $origin, 'https://'))) {
            $failures[] = 'Todas as origens de passkey devem usar HTTPS em produção.';
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
