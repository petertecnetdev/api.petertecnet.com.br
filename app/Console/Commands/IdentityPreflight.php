<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IdentityPreflight extends Command
{
    protected $signature = 'identity:preflight {--production : Enforce production invariants}';
    protected $description = 'Validate Peter Identity schema, Redis and security invariants before rollout.';

    public function handle(): int
    {
        $production = (bool) $this->option('production');
        $errors = [];
        $warnings = [];

        foreach (['identity_sessions', 'identity_challenges', 'identity_credentials', 'identity_security_settings', 'identity_devices', 'identity_global_sessions'] as $table) {
            if (! Schema::hasTable($table)) $errors[] = "Missing table: {$table}";
        }
        if (Schema::hasTable('identity_sessions') && ! Schema::hasColumn('identity_sessions', 'device_id')) {
            $errors[] = 'identity_sessions.device_id is missing.';
        }

        $accessTtl = (int) config('identity.access_token.ttl_minutes', 30);
        if ($accessTtl < 5 || $accessTtl > 60) $errors[] = 'Identity access-token TTL must stay between 5 and 60 minutes.';

        $legacyMode = strtolower((string) config('identity.legacy_tokens.mode', 'observe'));
        if (! in_array($legacyMode, ['observe', 'enforce'], true)) $errors[] = 'IDENTITY_LEGACY_TOKEN_MODE must be observe or enforce.';

        $store = (string) config('identity.global_sso.cache_store', 'redis');
        if ($production && $store !== 'redis') $errors[] = 'Production Identity cache store must be redis.';

        $probe = 'identity:preflight:'.Str::random(16);
        try {
            Cache::store($store)->put($probe, 'ok', 10);
            if (Cache::store($store)->get($probe) !== 'ok') $errors[] = "Identity cache store {$store} did not round-trip data.";
            Cache::store($store)->forget($probe);
        } catch (\Throwable $e) {
            if ($production) $errors[] = 'Redis/cache unavailable: '.$e->getMessage();
            else $warnings[] = 'Cache unavailable; database fallback will be used: '.$e->getMessage();
        }

        if ($production && ! str_starts_with(strtolower((string) config('app.url')), 'https://')) {
            $errors[] = 'APP_URL must use HTTPS in production.';
        }

        $rpId = strtolower((string) config('identity.passkeys.rp_id', ''));
        if ($production && $rpId !== 'petertecnet.com.br') $warnings[] = 'Passkey RP ID differs from petertecnet.com.br; verify this is intentional.';

        foreach ($warnings as $warning) $this->warn($warning);
        foreach ($errors as $error) $this->error($error);

        if ($errors !== []) {
            $this->error('Peter Identity preflight FAILED. Deployment must stop.');
            return self::FAILURE;
        }

        $this->info('Peter Identity preflight OK.');
        $this->line('Protocol: '.config('identity.protocol.version', '3.0'));
        $this->line('Access token TTL: '.$accessTtl.' min');
        $this->line('Legacy mode: '.$legacyMode);
        $this->line('Cache store: '.$store);
        return self::SUCCESS;
    }
}
