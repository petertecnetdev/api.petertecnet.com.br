<?php

namespace App\Console\Commands;

use App\Jobs\ScanEventDiscoverySource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RunEventDiscovery extends Command
{
    protected $signature = 'events:discover {--source= : ID de fonte específica} {--inline : Executa sem fila para diagnóstico}';

    protected $description = 'Despacha varreduras de fontes autorizadas para a fila Laravel.';

    public function handle(): int
    {
        if (! config('event_discovery.enabled', false)) {
            $this->warn('Event Discovery desativado. Defina EVENT_DISCOVERY_ENABLED=true após revisão.');
            return self::SUCCESS;
        }

        $query = DB::table('event_discovery_sources')->where('enabled', true);
        if ($id = $this->option('source')) $query->where('id', (int) $id);

        $sources = $query->orderBy('last_scanned_at')
            ->limit((int) config('event_discovery.max_sources_per_run', 20))
            ->pluck('id');

        foreach ($sources as $id) {
            if ($this->option('inline')) {
                app(ScanEventDiscoverySource::class, ['sourceId' => (int) $id])
                    ->handle(app(\App\Domain\EventDiscovery\Services\DiscoveryFeedService::class));
            } else {
                ScanEventDiscoverySource::dispatch((int) $id);
            }
        }

        $this->info('Fontes encaminhadas para varredura: '.$sources->count());
        return self::SUCCESS;
    }
}
