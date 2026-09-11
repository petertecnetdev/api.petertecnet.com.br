<?php

namespace App\Console\Commands;

use App\Domain\Discovery\Services\GlobalSearchService;
use App\Domain\Discovery\Services\SearchQueryParser;
use App\Models\Application;
use App\Models\User;
use App\Services\AppNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NotifySavedSearches extends Command
{
    protected $signature = 'search:notify-saved {--limit=200}';
    protected $description = 'Notifica usuários quando buscas salvas ganham novos resultados relevantes.';

    public function handle(
        SearchQueryParser $parser,
        GlobalSearchService $search,
        AppNotificationService $notifications
    ): int {
        if (! Schema::hasTable('search_saved_queries')) {
            $this->info('Infraestrutura de buscas salvas ainda não migrada.');
            return self::SUCCESS;
        }

        $rows = DB::table('search_saved_queries')
            ->where('notifications_enabled', true)
            ->orderByRaw('COALESCE(last_notified_at, created_at) ASC')
            ->limit(max(1, min(1000, (int) $this->option('limit'))))
            ->get();

        $sent = 0;
        foreach ($rows as $row) {
            $application = Application::query()->find((int) $row->app_id);
            $user = User::query()->find((int) $row->user_id);
            if (! $application || ! $user) continue;

            $filters = json_decode((string) ($row->filters ?? '{}'), true) ?: [];
            $parsed = $parser->parse(array_merge($filters, ['q' => $row->query]));
            $payload = $search->search((int) $row->app_id, $user, $parsed, 'all', 6, 1);
            $results = collect($payload['results'] ?? [])->reject(fn ($item) => ! empty($item['sponsored']));

            $since = $row->last_notified_at
                ? Carbon::parse($row->last_notified_at)
                : Carbon::parse($row->created_at);

            $fresh = $results->filter(function ($item) use ($since) {
                $meta = (array) ($item['meta'] ?? []);
                foreach (['created_at', 'updated_at', 'start_date'] as $field) {
                    if (empty($meta[$field])) continue;
                    try {
                        if (Carbon::parse($meta[$field])->gt($since)) return true;
                    } catch (\Throwable) {
                    }
                }
                return false;
            })->values();

            if ($fresh->isEmpty()) continue;

            $top = $fresh->first();
            $notifications->sendToUser((int) $row->app_id, (int) $row->user_id, [
                'type' => 'saved_search_new_results',
                'title' => 'Sua pesquisa salva tem novidades',
                'message' => sprintf(
                    'Encontramos %d novidade%s para “%s”.',
                    $fresh->count(),
                    $fresh->count() === 1 ? '' : 's',
                    $row->label ?: $row->query
                ),
                'reference_type' => 'search',
                'reference_id' => (int) $row->id,
                'reference_url' => '/search?q='.urlencode((string) $row->query),
                'data' => [
                    'saved_search_id' => (int) $row->id,
                    'query' => $row->query,
                    'top_result_type' => $top['type'] ?? null,
                    'top_result_id' => $top['id'] ?? null,
                ],
                'send_email' => false,
            ]);

            DB::table('search_saved_queries')->where('id', $row->id)->update([
                'last_notified_at' => now(),
                'updated_at' => now(),
            ]);
            $sent++;
        }

        $this->info("Alertas de pesquisas salvas enviados: {$sent}");

        return self::SUCCESS;
    }
}
