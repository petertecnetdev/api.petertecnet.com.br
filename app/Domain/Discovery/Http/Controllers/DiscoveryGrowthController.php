<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Domain\Discovery\Services\DiscoveryLearningService;
use App\Domain\Discovery\Services\DiscoverySearchIndexService;
use App\Domain\Discovery\Services\SearchPerformanceSyncService;
use App\Http\Controllers\Controller;
use App\Models\ContentEntry;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiscoveryGrowthController extends Controller
{
    public function __construct(
        private readonly DiscoveryLearningService $learning,
        private readonly DiscoverySearchIndexService $searchIndex,
        private readonly SearchPerformanceSyncService $searchPerformance,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $this->authorizeActor($request);
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:180']]);
        $days = (int) ($data['days'] ?? 30);
        $from = now()->subDays($days)->toDateString();
        $search = DB::table('search_performance_records')->where('measured_on', '>=', $from)
            ->selectRaw('provider, SUM(clicks) clicks, SUM(impressions) impressions, CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) * 1.0 / SUM(impressions) ELSE 0 END ctr, AVG(position) position')
            ->groupBy('provider')->get()->map(fn ($row) => [
                'provider' => $row->provider, 'clicks' => (int) $row->clicks, 'impressions' => (int) $row->impressions,
                'ctr' => round((float) $row->ctr * 100, 2), 'position' => $row->position !== null ? round((float) $row->position, 1) : null,
            ])->values();
        return response()->json(['success' => true, 'data' => [
            'providers' => $this->searchPerformance->status(), 'search_performance' => $search,
            'opportunities' => $this->learning->opportunities($days), 'attribution' => $this->learning->attribution($days),
            'experiments' => $this->learning->experimentStats(), 'accessibility' => $this->learning->accessibilitySummary($days),
            'public_health' => $this->learning->healthSummary(), 'quality' => $this->qualityScores(),
            'search_index_documents' => DB::table('discovery_search_documents')->count(),
        ]]);
    }

    public function syncSearchPerformance(Request $request): JsonResponse
    {
        $this->authorizeActor($request);
        $data = $request->validate(['provider' => ['nullable', 'string', 'in:google,bing'], 'days' => ['nullable', 'integer', 'min:2', 'max:90']]);
        return response()->json(['success' => true, 'data' => $this->searchPerformance->sync($data['provider'] ?? null, (int) ($data['days'] ?? 28))]);
    }

    public function importSearchPerformance(Request $request): JsonResponse
    {
        $this->authorizeActor($request);
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:24'], 'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'rows' => ['required', 'array', 'max:25000'], 'rows.*.date' => ['required', 'date'], 'rows.*.query' => ['nullable', 'string', 'max:500'],
            'rows.*.page' => ['nullable', 'string', 'max:1000'], 'rows.*.device' => ['nullable', 'string', 'max:32'], 'rows.*.country' => ['nullable', 'string', 'max:16'],
            'rows.*.clicks' => ['nullable', 'numeric', 'min:0'], 'rows.*.impressions' => ['nullable', 'numeric', 'min:0'], 'rows.*.ctr' => ['nullable', 'numeric', 'min:0'], 'rows.*.position' => ['nullable', 'numeric', 'min:0'],
        ]);
        $imported = $this->searchPerformance->import($data['provider'], $data['rows'], isset($data['application_id']) ? (int) $data['application_id'] : null);
        return response()->json(['success' => true, 'data' => ['imported' => $imported]]);
    }

    public function rebuildIndex(Request $request): JsonResponse
    {
        $this->authorizeActor($request);
        return response()->json(['success' => true, 'data' => ['documents' => $this->searchIndex->rebuild()]]);
    }

    public function monitor(Request $request): JsonResponse
    {
        $this->authorizeActor($request);
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:3', 'max:300']]);
        if (! DB::table('discovery_search_documents')->exists()) $this->searchIndex->rebuild();
        return response()->json(['success' => true, 'data' => ['checked' => $this->learning->monitorPublicPages((int) ($data['limit'] ?? 80))]]);
    }

    public function storeExperiment(Request $request): JsonResponse
    {
        $this->authorizeActor($request);
        $data = $this->experimentData($request);
        $id = DB::table('discovery_experiments')->insertGetId([
            'application_id' => $data['application_id'] ?? null, 'key' => $data['key'], 'name' => $data['name'], 'surface' => $data['surface'], 'status' => $data['status'] ?? 'draft',
            'goal_event' => $data['goal_event'] ?? 'conversion', 'allocation_percent' => (int) ($data['allocation_percent'] ?? 100),
            'variants' => json_encode($data['variants']), 'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['success' => true, 'data' => ['id' => $id]], 201);
    }

    public function updateExperiment(Request $request, int $experiment): JsonResponse
    {
        $this->authorizeActor($request);
        abort_unless(DB::table('discovery_experiments')->where('id', $experiment)->exists(), 404);
        $data = $this->experimentData($request, true);
        $payload = collect($data)->mapWithKeys(fn ($value, $key) => [in_array($key, ['variants', 'metadata'], true) ? $key : $key => in_array($key, ['variants', 'metadata'], true) && $value !== null ? json_encode($value) : $value])->all();
        foreach (['variants', 'metadata'] as $jsonKey) if (array_key_exists($jsonKey, $data)) $payload[$jsonKey] = $data[$jsonKey] === null ? null : json_encode($data[$jsonKey]);
        $payload['updated_at'] = now();
        DB::table('discovery_experiments')->where('id', $experiment)->update($payload);
        return response()->json(['success' => true]);
    }

    private function experimentData(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'key' => [$prefix, 'string', 'max:120', $partial ? 'unique:discovery_experiments,key,' . $request->route('experiment') : 'unique:discovery_experiments,key'],
            'name' => [$prefix, 'string', 'max:180'], 'surface' => [$prefix, 'string', 'max:160'], 'status' => ['sometimes', 'string', 'in:draft,running,paused,completed'],
            'goal_event' => ['sometimes', 'string', 'max:48'], 'allocation_percent' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'variants' => [$prefix, 'array', 'min:2', 'max:8'], 'variants.*.key' => ['required_with:variants', 'string', 'max:80'], 'variants.*.payload' => ['required_with:variants', 'array'],
            'metadata' => ['nullable', 'array'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
    }

    private function qualityScores(): array
    {
        $rows = collect();
        ContentEntry::query()->limit(1000)->get()->each(function ($entry) use ($rows) {
            $checks = ['title' => mb_strlen(trim((string) $entry->title)) >= 20, 'description' => mb_strlen(trim((string) ($entry->seo_description ?: $entry->excerpt))) >= 70, 'content' => mb_strlen(trim(strip_tags((string) $entry->content))) >= 450, 'image' => (bool) ($entry->cover_image || $entry->og_image), 'taxonomy' => (bool) ($entry->category || $entry->cluster || count($entry->tags ?: []))];
            $rows->push($this->qualityRow('content', $entry->id, $entry->title, '/blog/' . $entry->slug, $checks));
        });
        Establishment::query()->where('is_cancelled', false)->limit(1000)->get()->each(function ($row) use ($rows) {
            $name = $row->fantasy ?: $row->name;
            $checks = ['name' => mb_strlen(trim((string) $name)) >= 3, 'description' => mb_strlen(trim((string) $row->description)) >= 80, 'location' => (bool) ($row->city && $row->uf), 'category' => (bool) ($row->category || $row->type), 'contact' => (bool) ($row->phone || $row->email || $row->website_url)];
            $rows->push($this->qualityRow('establishment', $row->id, $name, '/empresas/' . $row->slug, $checks));
        });
        Item::query()->where('status', true)->where('entity_name', 'establishment')->limit(2000)->get()->each(function ($row) use ($rows) {
            $checks = ['name' => mb_strlen(trim((string) $row->name)) >= 3, 'description' => mb_strlen(trim((string) $row->description)) >= 70, 'image' => (bool) $row->image_url, 'category' => (bool) ($row->category || $row->type), 'commercial' => $row->price !== null || $row->type === 'service'];
            $rows->push($this->qualityRow('item', $row->id, $row->name, '/solucoes/' . ($row->slug ?: $row->id), $checks));
        });
        return ['average' => $rows->count() ? round((float) $rows->avg('score'), 1) : null, 'ready' => $rows->where('score', '>=', 80)->count(), 'needs_attention' => $rows->where('score', '<', 60)->count(), 'rows' => $rows->sortBy('score')->take(100)->values()->all()];
    }

    private function qualityRow(string $type, int $id, string $label, string $url, array $checks): array
    {
        $score = (int) round(collect($checks)->filter()->count() / max(1, count($checks)) * 100);
        return ['type' => $type, 'id' => $id, 'label' => $label, 'url' => $url, 'score' => $score, 'missing' => collect($checks)->filter(fn ($ok) => ! $ok)->keys()->values()->all(), 'ready_to_publish' => $score >= 80];
    }

    private function authorizeActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor && ($actor->hasProfile('Administrador') || $actor->hasPermission('marketing_dashboard') || $actor->hasPermission('content_manage')), 403);
        return $actor;
    }
}
