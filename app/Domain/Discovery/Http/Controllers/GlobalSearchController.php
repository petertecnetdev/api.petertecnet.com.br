<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Domain\Discovery\Services\GlobalSearchService;
use App\Domain\Discovery\Services\SearchAnalyticsService;
use App\Domain\Discovery\Services\SearchCampaignService;
use App\Domain\Discovery\Services\SearchQueryParser;
use App\Http\Controllers\Controller;
use App\Models\Production;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

final class GlobalSearchController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SearchQueryParser $parser,
        private readonly GlobalSearchService $search,
        private readonly SearchAnalyticsService $analytics,
        private readonly SearchCampaignService $campaigns,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate($this->searchRules(true));
        $parsed = $this->parser->parse($data);
        $type = $data['type'] ?? 'all';
        $limit = (int) ($data['per_type'] ?? ($type === 'all' ? 8 : 20));
        $page = (int) ($data['page'] ?? 1);
        $viewer = $request->user('api');
        $payload = $this->search->search($this->context->id(), $viewer, $parsed, $type, $limit, $page);

        $searchId = $this->analytics->logSearch(
            $this->context->id(),
            $viewer?->id ? (int) $viewer->id : null,
            $this->sessionKey($request),
            $parsed,
            $type,
            (int) ($payload['counts']['total'] ?? 0),
            (string) ($data['source'] ?? 'global')
        );

        $payload['search_id'] = $searchId;

        return response()->json($payload)
            ->header('Cache-Control', $viewer ? 'private, no-store' : 'public, max-age=15, stale-while-revalidate=30');
    }

    public function suggestions(Request $request)
    {
        $data = $request->validate(array_merge($this->searchRules(false), [
            'q' => 'required|string|min:1|max:120',
            'limit' => 'nullable|integer|min:3|max:15',
        ]));
        $parsed = $this->parser->parse($data);

        return response()->json($this->search->suggestions(
            $this->context->id(),
            $request->user('api'),
            $parsed,
            (int) ($data['limit'] ?? 10)
        ))->header('Cache-Control', 'private, max-age=10, stale-while-revalidate=30');
    }

    public function discover(Request $request)
    {
        $data = $request->validate([
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'lat' => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng' => 'nullable|numeric|between:-180,180|required_with:lat',
        ]);

        return response()->json($this->search->discover(
            $this->context->id(),
            $request->user('api'),
            $data
        ))->header('Cache-Control', 'private, max-age=30, stale-while-revalidate=120');
    }

    public function trending(Request $request)
    {
        $data = $request->validate([
            'city' => 'nullable|string|max:120',
            'days' => 'nullable|integer|min:1|max:90',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        return response()->json([
            'terms' => $this->search->popularTerms(
                $this->context->id(),
                (int) ($data['days'] ?? 7),
                $data['city'] ?? null,
                (int) ($data['limit'] ?? 20)
            ),
        ])->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=180');
    }

    public function click(Request $request)
    {
        $data = $request->validate([
            'search_id' => 'nullable|integer|min:1',
            'target_type' => 'required|string|max:40',
            'target_id' => 'required|integer|min:1',
            'position' => 'nullable|integer|min:1|max:1000',
            'sponsored' => 'nullable|boolean',
            'item' => 'nullable|array',
            'item.type' => 'nullable|string|max:40',
            'item.id' => 'nullable|integer|min:1',
            'item.title' => 'nullable|string|max:255',
            'item.subtitle' => 'nullable|string|max:500',
            'item.image' => 'nullable|string|max:2048',
            'item.url' => 'nullable|string|max:2048',
        ]);

        $viewer = $request->user('api');
        $clickId = $this->analytics->logClick(
            $this->context->id(),
            $viewer?->id ? (int) $viewer->id : null,
            isset($data['search_id']) ? (int) $data['search_id'] : null,
            $data['target_type'],
            (int) $data['target_id'],
            isset($data['position']) ? (int) $data['position'] : null,
            (bool) ($data['sponsored'] ?? false)
        );

        if ($viewer && ! empty($data['item'])) {
            $this->analytics->rememberEntity($this->context->id(), (int) $viewer->id, $data['item']);
        }

        return response()->json(['click_id' => $clickId], 201);
    }

    public function convert(Request $request)
    {
        $data = $request->validate([
            'conversion_type' => 'required|in:ticket_purchase,follow,direct_open,save,share',
            'target_id' => 'required|integer|min:1',
        ]);

        return response()->json([
            'attributed' => $this->analytics->markConversion(
                $this->context->id(),
                $request->user('api')?->id ? (int) $request->user('api')->id : null,
                $data['conversion_type'],
                (int) $data['target_id']
            ),
        ]);
    }

    public function recent(Request $request)
    {
        $viewer = $request->user();
        $data = $request->validate(['limit' => 'nullable|integer|min:1|max:50']);

        return response()->json([
            'recent' => $this->analytics->recent($this->context->id(), (int) $viewer->id, (int) ($data['limit'] ?? 20)),
        ]);
    }

    public function clearRecent(Request $request)
    {
        $viewer = $request->user();
        $data = $request->validate([
            'type' => 'nullable|string|max:40',
            'target_id' => 'nullable|integer|min:1',
        ]);

        return response()->json([
            'deleted' => $this->analytics->clearRecent(
                $this->context->id(),
                (int) $viewer->id,
                $data['type'] ?? null,
                isset($data['target_id']) ? (int) $data['target_id'] : null
            ),
        ]);
    }

    public function saved(Request $request)
    {
        return response()->json([
            'saved' => $this->analytics->savedQueries($this->context->id(), (int) $request->user()->id),
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate(array_merge($this->searchRules(false), [
            'q' => 'required|string|min:1|max:120',
            'label' => 'nullable|string|max:120',
            'notifications_enabled' => 'nullable|boolean',
        ]));
        $parsed = $this->parser->parse($data);

        return response()->json([
            'saved' => $this->analytics->saveQuery(
                $this->context->id(),
                (int) $request->user()->id,
                $parsed,
                $data['label'] ?? null,
                (bool) ($data['notifications_enabled'] ?? false)
            ),
        ], 201);
    }

    public function deleteSaved(Request $request, int $id)
    {
        return response()->json([
            'deleted' => $this->analytics->deleteSavedQuery($this->context->id(), (int) $request->user()->id, $id),
        ]);
    }

    public function producerInsights(Request $request)
    {
        $data = $request->validate([
            'production_id' => 'required|integer|min:1',
            'days' => 'nullable|integer|min:1|max:180',
        ]);

        $production = Production::query()
            ->where('app_id', $this->context->id())
            ->findOrFail((int) $data['production_id']);

        $user = $request->user();
        abort_unless($user->hasProfile('Administrador') || (int) $production->user_id === (int) $user->id, 403);

        return response()->json($this->analytics->producerDemand(
            $this->context->id(),
            (int) $production->id,
            (int) ($data['days'] ?? 30)
        ));
    }

    public function adminAnalytics(Request $request)
    {
        $this->assertPeterAdmin($request);
        $data = $request->validate(['days' => 'nullable|integer|min:1|max:365']);

        return response()->json($this->analytics->adminOverview(
            $this->context->id(),
            (int) ($data['days'] ?? 30)
        ));
    }

    public function campaigns(Request $request)
    {
        $this->assertPeterAdmin($request);

        return response()->json(['campaigns' => $this->campaigns->list($this->context->id())]);
    }

    public function storeCampaign(Request $request)
    {
        $this->assertPeterAdmin($request);
        $data = $request->validate($this->campaignRules(true));

        return response()->json([
            'campaign' => $this->campaigns->create($this->context->id(), (int) $request->user()->id, $data),
        ], 201);
    }

    public function updateCampaign(Request $request, int $id)
    {
        $this->assertPeterAdmin($request);
        $data = $request->validate($this->campaignRules(false));

        return response()->json([
            'campaign' => $this->campaigns->update($this->context->id(), $id, $data),
        ]);
    }

    public function deleteCampaign(Request $request, int $id)
    {
        $this->assertPeterAdmin($request);

        return response()->json(['deleted' => $this->campaigns->delete($this->context->id(), $id)]);
    }

    private function searchRules(bool $requireQuery): array
    {
        return [
            'q' => ($requireQuery ? 'required' : 'nullable').'|string|min:1|max:120',
            'type' => 'nullable|in:all,event,production,artist,user,post,item,venue,promoter',
            'per_type' => 'nullable|integer|min:1|max:30',
            'page' => 'nullable|integer|min:1|max:100',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'period' => 'nullable|in:today,tomorrow,weekend,next7,next30',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'free' => 'nullable|boolean',
            'available' => 'nullable|boolean',
            'format' => 'nullable|in:in_person,online,hybrid',
            'max_price' => 'nullable|numeric|min:0|max:1000000',
            'min_price' => 'nullable|numeric|min:0|max:1000000',
            'lat' => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng' => 'nullable|numeric|between:-180,180|required_with:lat',
            'radius_km' => 'nullable|integer|min:1|max:500',
            'sort' => 'nullable|in:relevance,nearby,popular,newest,soonest',
            'category' => 'nullable|string|max:120',
            'artist_id' => 'nullable|integer|min:1',
            'production_id' => 'nullable|integer|min:1',
            'source' => 'nullable|string|max:40',
        ];
    }

    private function campaignRules(bool $creating): array
    {
        $required = $creating ? 'required|' : 'sometimes|';

        return [
            'owner_user_id' => 'nullable|integer|min:1',
            'production_id' => 'nullable|integer|min:1',
            'target_type' => $required.'in:event,production,artist,user',
            'target_id' => $required.'integer|min:1',
            'label' => 'nullable|string|max:160',
            'keywords' => $required.'array|max:50',
            'keywords.*' => 'string|max:80',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'priority' => 'nullable|integer|min:0|max:1000',
            'bid_cents' => 'nullable|integer|min:0|max:100000000',
            'budget_cents' => 'nullable|integer|min:0|max:1000000000',
            'status' => 'nullable|in:draft,active,paused,ended',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ];
    }

    private function sessionKey(Request $request): ?string
    {
        $value = trim((string) $request->header('X-Search-Session', ''));

        return $value !== '' ? mb_substr($value, 0, 64) : null;
    }

    private function assertPeterAdmin(Request $request): void
    {
        $email = strtolower(trim((string) ($request->user()?->email ?? '')));
        abort_unless($email === 'petertecnet@gmail.com', 403, 'Acesso administrativo não autorizado.');
    }
}
