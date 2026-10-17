<?php

namespace App\Domain\EventDiscovery\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class DiscoverySourceRegistry
{
    public function __construct(private readonly DiscoverySourcePolicy $policy) {}

    public function listForApplication(int $appId): array
    {
        return DB::table('event_discovery_sources')
            ->where('app_id', $appId)
            ->orderBy('name')
            ->get(['id', 'name', 'format', 'url', 'default_city', 'default_uf',
                'timezone', 'enabled', 'last_scanned_at', 'last_error', 'authorized_at'])
            ->all();
    }

    public function register(int $appId, array $input, ?int $actorId): object
    {
        if (! filter_var($input['authorization_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages([
                'authorization_confirmed' => 'Confirme que o acesso automatizado à fonte foi autorizado.',
            ]);
        }

        try {
            $this->policy->assertAllowed(trim((string) $input['url']));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }
        if (trim((string) ($input['name'] ?? '')) === '') {
            throw ValidationException::withMessages(['name' => 'Informe o nome da fonte.']);
        }
        $timezone = (string) ($input['timezone'] ?? 'America/Sao_Paulo');
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['timezone' => 'Timezone IANA inválido.']);
        }

        $uf = strtoupper(trim((string) ($input['uf'] ?? '')));
        if ($uf !== '' && ! preg_match('/^[A-Z]{2}$/', $uf)) {
            throw ValidationException::withMessages(['uf' => 'UF inválida.']);
        }

        $url = trim((string) $input['url']);
        $key = hash('sha256', $url);
        return DB::transaction(function () use ($appId, $input, $actorId, $url, $key, $uf, $timezone): object {
            $existing = DB::table('event_discovery_sources')
                ->where('app_id', $appId)->where('source_key', $key)
                ->lockForUpdate()->first();

            $values = [
                'name' => trim((string) $input['name']),
                'format' => 'json',
                'url' => $url,
                'default_city' => trim((string) ($input['city'] ?? '')) ?: null,
                'default_uf' => $uf ?: null,
                'timezone' => $timezone,
                'enabled' => true,
                'authorized_at' => now(),
                'authorized_by_user_id' => $actorId,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('event_discovery_sources')->where('id', $existing->id)->update($values);
                $id = $existing->id;
            } else {
                $id = DB::table('event_discovery_sources')->insertGetId(array_merge($values, [
                    'app_id' => $appId,
                    'source_key' => $key,
                    'created_at' => now(),
                ]));
            }

            return DB::table('event_discovery_sources')->where('id', $id)->first();
        }, 3);
    }

    public function setEnabled(int $appId, int $sourceId, bool $enabled): object
    {
        return DB::transaction(function () use ($appId, $sourceId, $enabled): object {
            $source = DB::table('event_discovery_sources')->where('app_id', $appId)
                ->lockForUpdate()->find($sourceId);
            abort_unless($source, 404, 'Fonte não encontrada.');

            if ($enabled) $this->policy->assertAllowed($source->url);

            DB::table('event_discovery_sources')->where('id', $sourceId)->update([
                'enabled' => $enabled, 'updated_at' => now(),
            ]);
            return DB::table('event_discovery_sources')->where('id', $sourceId)->first();
        }, 3);
    }
}
