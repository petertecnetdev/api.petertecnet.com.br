<?php

namespace App\Console\Commands;

use App\Models\Interaction;
use App\Services\ApplicationContextService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditInteractionQuality extends Command
{
    protected $signature = 'interactions:audit-quality {--repair : Reclassify interactions when the stored source is deterministic} {--limit=0 : Maximum records to inspect; zero means all}';
    protected $description = 'Audit application attribution, unresolved sources and duplicate interaction request IDs';

    public function handle(ApplicationContextService $context): int
    {
        $repair = (bool) $this->option('repair');
        $limit = max((int) $this->option('limit'), 0);
        $stats = [
            'inspected' => 0,
            'correct' => 0,
            'mismatched' => 0,
            'repaired' => 0,
            'unresolved' => 0,
        ];

        $query = Interaction::query()->orderBy('id');
        if ($limit > 0) $query->limit($limit);

        $query->chunkById(500, function ($interactions) use ($context, $repair, &$stats, $limit) {
            foreach ($interactions as $interaction) {
                if ($limit > 0 && $stats['inspected'] >= $limit) return false;
                $stats['inspected']++;
                $content = is_array($interaction->content) ? $interaction->content : [];
                $source = $context->resolveStoredContext($content);

                if (! $source) {
                    $stats['unresolved']++;
                    continue;
                }

                if ((int) $interaction->app_id === (int) $source->id) {
                    $stats['correct']++;
                    continue;
                }

                $stats['mismatched']++;
                if (! $repair) continue;

                $content['data_quality'] = array_merge((array) ($content['data_quality'] ?? []), [
                    'application_attribution' => 'repaired',
                    'original_app_id' => $interaction->app_id,
                    'resolved_app_id' => $source->id,
                    'resolved_app_slug' => $source->slug,
                    'repaired_at' => now()->toIso8601String(),
                ]);

                Interaction::withoutEvents(fn () => $interaction->update([
                    'app_id' => $source->id,
                    'content' => $content,
                ]));
                $stats['repaired']++;
            }
        });

        $duplicateGroups = DB::table('interactions')
            ->whereNotNull('request_id')
            ->where('request_id', '!=', '')
            ->selectRaw('app_id, request_id, COUNT(*) total')
            ->groupBy('app_id', 'request_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $this->table(['Métrica', 'Total'], [
            ['Inspecionadas', $stats['inspected']],
            ['Atribuição correta', $stats['correct']],
            ['Atribuição divergente', $stats['mismatched']],
            ['Corrigidas', $stats['repaired']],
            ['Origem não determinável', $stats['unresolved']],
            ['Grupos de request_id duplicados', $duplicateGroups],
        ]);

        $distribution = Interaction::query()
            ->leftJoin('applications', 'applications.id', '=', 'interactions.app_id')
            ->selectRaw("COALESCE(applications.slug, 'sem-aplicacao') application, COUNT(*) total")
            ->groupBy('applications.slug')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [$row->application, $row->total])
            ->all();

        $this->table(['Aplicação', 'Interações'], $distribution);
        $this->info($repair ? 'Auditoria e reparo concluídos.' : 'Auditoria concluída sem alterações. Use --repair após revisar os totais.');

        return self::SUCCESS;
    }
}
