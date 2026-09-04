<?php

namespace App\Console\Commands;

use App\Models\Production;
use App\Services\ProductionEstablishmentSyncService;
use Illuminate\Console\Command;

class SyncProductionEstablishments extends Command
{
    protected $signature = 'productions:sync-establishments {--app=} {--production=}';

    protected $description = 'Sincroniza produções legadas com o cadastro genérico de establishments.';

    public function handle(ProductionEstablishmentSyncService $sync): int
    {
        $query = Production::query()->orderBy('id');

        if ($this->option('app')) {
            $query->where('app_id', (int) $this->option('app'));
        }

        if ($this->option('production')) {
            $query->whereKey((int) $this->option('production'));
        }

        $count = 0;
        $query->chunkById(100, function ($productions) use ($sync, &$count) {
            foreach ($productions as $production) {
                if ($sync->sync($production)) {
                    $count++;
                }
            }
        });

        $this->info("{$count} produção(ões) sincronizada(s) com establishments.");

        return self::SUCCESS;
    }
}
