<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Domain\Discovery\Services\DiscoveryService;
use App\Http\Controllers\Controller;
use App\Models\ContentEntry;
use App\Models\DiscoveryEvent;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SeoDiagnosticsController extends Controller
{
    public function __construct(private readonly DiscoveryService $discovery)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $data = $request->validate([
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
        ]);

        $applicationId = isset($data['application_id']) ? (int) $data['application_id'] : null;
        if ($applicationId && ! $actor->hasProfile('Administrador')) {
            abort_unless($actor->applications()->whereKey($applicationId)->exists(), 403);
        }
        $allowed = $actor->hasProfile('Administrador') ? null : $actor->applications()->pluck('applications.id')->map(fn ($id) => (int) $id)->all();

        $contents = ContentEntry::query()
            ->when($applicationId, fn ($query) => $query->where('application_id', $applicationId))
            ->when($allowed !== null && ! $applicationId, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('application_id')->orWhereIn('application_id', $allowed)))
            ->limit(1000)->get();

        $establishments = Establishment::query()
            ->where('is_cancelled', false)->where('is_published', true)
            ->when($applicationId, fn ($query) => $query->where('app_id', $applicationId))
            ->when($allowed !== null && ! $applicationId, fn ($query) => $query->whereIn('app_id', $allowed))
            ->limit(1000)->get();

        $establishmentIds = $establishments->pluck('id');
        $items = Item::query()->with(['files', 'establishment'])
            ->where('status', true)->where('entity_name', 'establishment')
            ->when($applicationId, fn ($query) => $query->where(fn ($apps) => $apps->where('app_id', $applicationId)->orWhereIn('entity_id', $establishmentIds)))
            ->when($allowed !== null && ! $applicationId, fn ($query) => $query->where(fn ($apps) => $apps->whereIn('app_id', $allowed)->orWhereIn('entity_id', $establishmentIds)))
            ->limit(1500)->get();

        $issues = collect();
        $titles = collect();
        foreach ($contents as $entry) {
            $seo = $this->discovery->contentSeo($entry);
            $titles->push(['title' => Str::lower($seo['title']), 'type' => 'content', 'id' => $entry->id, 'label' => $entry->title, 'url' => '/blog/' . $entry->slug]);
            if (! trim((string) $entry->excerpt) && ! trim((string) $entry->seo_description)) $issues->push($this->issue('content', $entry->id, $entry->title, '/blog/' . $entry->slug, 'missing_description', 'alta', 'Conteúdo sem descrição SEO ou resumo.'));
            if (mb_strlen(trim(strip_tags((string) $entry->content))) < 450) $issues->push($this->issue('content', $entry->id, $entry->title, '/blog/' . $entry->slug, 'thin_content', 'media', 'Conteúdo muito curto para sustentar uma intenção de busca.'));
            if (! $entry->cover_image && ! $entry->og_image) $issues->push($this->issue('content', $entry->id, $entry->title, '/blog/' . $entry->slug, 'missing_image', 'media', 'Conteúdo sem imagem de capa; o social card automático continuará disponível.'));
        }

        foreach ($establishments as $establishment) {
            $seo = $this->discovery->establishmentSeo($establishment);
            $name = $establishment->fantasy ?: $establishment->name;
            $titles->push(['title' => Str::lower($seo['title']), 'type' => 'establishment', 'id' => $establishment->id, 'label' => $name, 'url' => '/empresas/' . $establishment->slug]);
            if (! trim((string) $establishment->description)) $issues->push($this->issue('establishment', $establishment->id, $name, '/empresas/' . $establishment->slug, 'missing_description', 'alta', 'Empresa publicada sem descrição.'));
            if (! $establishment->city || ! $establishment->uf) $issues->push($this->issue('establishment', $establishment->id, $name, '/empresas/' . $establishment->slug, 'missing_location', 'media', 'Localização incompleta reduz o potencial de SEO local.'));
        }

        foreach ($items as $item) {
            if (! $item->establishment) continue;
            $seo = $this->discovery->itemSeo($item, $item->establishment);
            $identifier = $item->slug ?: (string) $item->id;
            $titles->push(['title' => Str::lower($seo['title']), 'type' => 'item', 'id' => $item->id, 'label' => $item->name, 'url' => '/solucoes/' . $identifier]);
            if (! trim((string) $item->description)) $issues->push($this->issue('item', $item->id, $item->name, '/solucoes/' . $identifier, 'missing_description', 'alta', 'Item ativo sem descrição.'));
            elseif (mb_strlen(trim(strip_tags((string) $item->description))) < 70) $issues->push($this->issue('item', $item->id, $item->name, '/solucoes/' . $identifier, 'short_description', 'media', 'Descrição do item é curta para busca e compartilhamento.'));
            if (! $item->image_url) $issues->push($this->issue('item', $item->id, $item->name, '/solucoes/' . $identifier, 'missing_image', 'alta', 'Item ativo sem imagem.'));
            if (! $item->category && ! $item->type) $issues->push($this->issue('item', $item->id, $item->name, '/solucoes/' . $identifier, 'missing_category', 'media', 'Item sem categoria ou tipo.'));
        }

        $duplicates = $titles->groupBy('title')->filter(fn (Collection $group) => $group->count() > 1);
        foreach ($duplicates as $group) {
            foreach ($group as $row) {
                $issues->push($this->issue($row['type'], $row['id'], $row['label'], $row['url'], 'duplicate_title', 'alta', 'Título SEO duplicado em outra página pública.'));
            }
        }

        $trafficFrom = now()->subDays(30);
        $trafficEntities = DiscoveryEvent::query()->where('occurred_at', '>=', $trafficFrom)
            ->whereNotNull('entity_type')->whereNotNull('entity_id')
            ->selectRaw('entity_type, entity_id, COUNT(*) total')
            ->groupBy('entity_type', 'entity_id')->get()
            ->keyBy(fn ($row) => Str::lower($row->entity_type) . ':' . $row->entity_id);

        $noTraffic = $titles->filter(function ($row) use ($trafficEntities) {
            $keys = [Str::lower($row['type']) . ':' . $row['id']];
            if ($row['type'] === 'content') $keys[] = 'content:' . Str::after($row['url'], '/blog/');
            if ($row['type'] === 'item') $keys[] = 'product:' . $row['id'];
            return collect($keys)->every(fn ($key) => ! $trafficEntities->has($key));
        })->take(100)->values();

        $scoreBase = max(1, $contents->count() + $establishments->count() + $items->count());
        $high = $issues->where('severity', 'alta')->count();
        $medium = $issues->where('severity', 'media')->count();
        $score = max(0, min(100, (int) round(100 - (($high * 5 + $medium * 2) / $scoreBase * 12))));

        return response()->json([
            'success' => true,
            'data' => [
                'score' => $score,
                'summary' => [
                    'indexable_pages' => $titles->count(),
                    'content' => $contents->count(),
                    'establishments' => $establishments->count(),
                    'items' => $items->count(),
                    'high_issues' => $high,
                    'medium_issues' => $medium,
                    'duplicate_titles' => $duplicates->count(),
                    'without_traffic_30d' => $noTraffic->count(),
                ],
                'issues' => $issues->sortBy(fn ($row) => $row['severity'] === 'alta' ? 0 : 1)->take(300)->values(),
                'without_traffic' => $noTraffic,
            ],
        ]);
    }

    private function issue(string $type, int|string $id, string $label, string $url, string $code, string $severity, string $message): array
    {
        return compact('type', 'id', 'label', 'url', 'code', 'severity', 'message');
    }

    private function authorizeActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor && ($actor->hasProfile('Administrador') || $actor->hasPermission('marketing_dashboard') || $actor->hasPermission('content_manage')), 403);
        return $actor;
    }
}
