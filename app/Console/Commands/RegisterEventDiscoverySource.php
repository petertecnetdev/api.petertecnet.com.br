<?php

namespace App\Console\Commands;

use App\Domain\EventDiscovery\Services\DiscoverySourcePolicy;
use App\Models\Application;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RegisterEventDiscoverySource extends Command
{
    protected $signature = 'events:discovery-source
        {application : Slug da aplicação}
        {name : Nome da fonte autorizada}
        {url : HTTPS do feed JSON estruturado}
        {--city= : Cidade padrão}
        {--uf= : UF padrão}
        {--timezone=America/Sao_Paulo : Timezone IANA}
        {--authorized : Confirma autorização para consumo automatizado desta fonte}';

    protected $description = 'Registra ou atualiza uma fonte autorizada de eventos para descoberta.';

    public function handle(DiscoverySourcePolicy $policy): int
    {
        if (! $this->option('authorized')) {
            $this->error('Confirme --authorized após verificar os direitos de acesso ao feed.');
            return self::FAILURE;
        }

        $app = Application::query()->where('slug', $this->argument('application'))->first();
        if (! $app) {
            $this->error('Aplicação não encontrada.');
            return self::FAILURE;
        }

        $url = trim((string) $this->argument('url'));
        try {
            $policy->assertAllowed($url);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $timezone = (string) $this->option('timezone');
        $uf = strtoupper(trim((string) $this->option('uf')));
        if (! in_array($timezone, timezone_identifiers_list(), true)
            || ($uf !== '' && ! preg_match('/^[A-Z]{2}$/', $uf))
        ) {
            $this->error('Timezone ou UF inválidos.');
            return self::FAILURE;
        }

        $name = Str::limit(trim((string) $this->argument('name')), 160, '');
        $key = hash('sha256', $url);

        DB::table('event_discovery_sources')->updateOrInsert(
            ['app_id' => $app->id, 'source_key' => $key],
            [
                'name' => $name,
                'url' => $url,
                'format' => 'json',
                'default_city' => $this->option('city') ?: null,
                'default_uf' => $uf ?: null,
                'timezone' => $timezone,
                'enabled' => true,
                'authorized_at' => now(),
                'authorized_by_user_id' => null,
                'updated_at' => now(),
            ]
        );

        $this->info('Fonte autorizada registrada. Varreduras só executam com EVENT_DISCOVERY_ENABLED=true.');
        return self::SUCCESS;
    }
}
