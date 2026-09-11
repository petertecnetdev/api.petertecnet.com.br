<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Production;
use App\Services\AppNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SendSearchDemandAlerts extends Command
{
    protected $signature = 'search:demand-alerts {--hours=24} {--threshold=5}';
    protected $description = 'Notifica produtores quando existe demanda de busca relevante na cidade deles.';

    public function handle(AppNotificationService $notifications): int
    {
        if (! Schema::hasTable('search_queries')) {
            $this->info('Infraestrutura de busca ainda não migrada.');
            return self::SUCCESS;
        }

        $hours = max(1, min(168, (int) $this->option('hours')));
        $threshold = max(2, min(1000, (int) $this->option('threshold')));
        $from = now()->subHours($hours);

        $signals = DB::table('search_queries')
            ->where('created_at', '>=', $from)
            ->whereNotNull('city')
            ->where('normalized_query', '<>', '')
            ->selectRaw('app_id, city, uf, normalized_query, MAX(query) as query, COUNT(*) as searches, SUM(zero_result) as zero_results')
            ->groupBy('app_id', 'city', 'uf', 'normalized_query')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->orderByDesc('searches')
            ->limit(250)
            ->get();

        $sent = 0;
        foreach ($signals as $signal) {
            $app = Application::query()->find((int) $signal->app_id);
            if (! $app) continue;

            $productions = Production::query()
                ->where('app_id', (int) $signal->app_id)
                ->where('is_published', true)
                ->where(fn ($q) => $q->where('is_cancelled', false)->orWhereNull('is_cancelled'))
                ->whereRaw('LOWER(city) = LOWER(?)', [$signal->city])
                ->whereNotNull('user_id')
                ->limit(100)
                ->get(['id', 'user_id', 'name', 'slug']);

            foreach ($productions as $production) {
                $dedupe = 'search-demand-alert:'.sha1(implode(':', [
                    $signal->app_id,
                    $production->id,
                    $signal->normalized_query,
                    strtolower((string) $signal->city),
                ]));

                if (! Cache::add($dedupe, 1, now()->addHours(24))) {
                    continue;
                }

                $message = sprintf(
                    '%d pessoas pesquisaram por “%s” em %s nas últimas %d horas.%s',
                    (int) $signal->searches,
                    $signal->query,
                    $signal->city,
                    $hours,
                    (int) $signal->zero_results > 0 ? ' Parte dessas buscas ficou sem resultado.' : ''
                );

                $notifications->sendToUser((int) $signal->app_id, (int) $production->user_id, [
                    'type' => 'search_demand_signal',
                    'title' => 'Há demanda que sua produção pode aproveitar',
                    'message' => $message,
                    'reference_type' => 'production',
                    'reference_id' => (int) $production->id,
                    'reference_url' => '/producer/search-insights?production_id='.$production->id,
                    'data' => [
                        'query' => $signal->query,
                        'searches' => (int) $signal->searches,
                        'zero_results' => (int) $signal->zero_results,
                        'city' => $signal->city,
                        'uf' => $signal->uf,
                    ],
                    'send_email' => false,
                ]);
                $sent++;
            }
        }

        $this->info("Sinais de demanda enviados: {$sent}");

        return self::SUCCESS;
    }
}
