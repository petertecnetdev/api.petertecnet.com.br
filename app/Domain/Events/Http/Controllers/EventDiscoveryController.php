<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Commerce\Services\TicketInventoryService;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Ticket;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class EventDiscoveryController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TicketInventoryService $ticketInventory,
    ) {}

    public function events(Request $request)
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'category' => 'nullable|string|max:120',
            'artist_id' => 'nullable|integer|min:1',
            'production_id' => 'nullable|integer|min:1',
            'period' => 'nullable|in:today,tomorrow,weekend,friday,saturday,sunday,next7,next30,month,custom',
            'date' => 'nullable|date_format:Y-m-d',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'free' => 'nullable|boolean',
            'available' => 'nullable|boolean',
            'sort' => 'nullable|in:soonest,newest,popular',
            'lat' => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng' => 'nullable|numeric|between:-180,180|required_with:lat',
            'radius_km' => 'nullable|integer|min:1|max:500',
            'per_page' => 'nullable|integer|min:1|max:50',
            'view' => 'nullable|in:compact,full',
        ]);

        $appId = $this->context->id();
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $now = Carbon::now($timezone);
        [$from, $to] = $this->periodRange($data, $timezone);
        $cacheQuery = $request->query();
        ksort($cacheQuery);
        $cacheKey = 'event-discovery:v3:'.$appId.':'.hash('sha256', http_build_query($cacheQuery));
        if (($cached = Cache::get($cacheKey)) !== null) {
            return response()->json($cached)->header('X-Peter-Cache', 'HIT');
        }

        $query = Event::query()
            ->where('events.app_id', $appId)
            ->where('events.is_published', true)
            ->where('events.is_cancelled', false)
            ->where(fn ($q) => $q
                ->where('events.is_private', false)
                ->orWhereNull('events.is_private'))
            ->where(fn ($q) => $q
                ->whereNull('events.end_date')
                ->orWhere('events.end_date', '>', $now))
            ->with([
                'production:id,app_id,name,slug,user_id,app_slug,logo,city,uf',
                'artists:id,app_id,slug,stage_name,photo',
            ])
            ->withCount([
                'tickets as ticket_lots_count' => fn ($q) => $q->where('app_id', $appId),
                'tickets as configured_free_ticket_lots_count' => fn ($q) => $q->where('app_id', $appId)->where('price', 0),
                'tickets as free_ticket_lots_count' => function ($q) use ($appId, $now) {
                    $q->where('tickets.app_id', $appId)->where('tickets.price', 0);
                    $this->ticketInventory->constrainSellable($q, $appId, $now);
                },
                'tickets as sellable_ticket_lots_count' => function ($q) use ($appId, $now) {
                    $q->where('tickets.app_id', $appId);
                    $this->ticketInventory->constrainSellable($q, $appId, $now);
                },
                'tickets as sellable_free_ticket_lots_count' => function ($q) use ($appId, $now) {
                    $q->where('tickets.app_id', $appId)->where('tickets.price', 0);
                    $this->ticketInventory->constrainSellable($q, $appId, $now);
                },
            ]);

        if (!empty($data['city'])) {
            $query->whereRaw('LOWER(events.city) = LOWER(?)', [trim($data['city'])]);
        }
        if (!empty($data['uf'])) {
            $query->where('events.uf', strtoupper($data['uf']));
        }
        if (!empty($data['category'])) {
            $query->where('events.category', $data['category']);
        }
        if (!empty($data['production_id'])) {
            $query->where('events.production_id', $data['production_id']);
        }
        if (!empty($data['artist_id'])) {
            $query->whereHas('artists', fn ($q) => $q->where('artists.id', $data['artist_id']));
        }

        if ($from || $to) {
            $isToday = ($data['period'] ?? null) === 'today' && empty($data['date']);
            if ($isToday) {
                if ($from) {
                    $query->where(fn ($q) => $q
                        ->whereNull('events.end_date')
                        ->orWhere('events.end_date', '>=', $from));
                }
                if ($to) {
                    $query->where('events.start_date', '<=', $to);
                }
            } else {
                if ($from) {
                    $query->where('events.start_date', '>=', $from);
                }
                if ($to) {
                    $query->where('events.start_date', '<=', $to);
                }
            }
        }

        if (!empty($data['q'])) {
            $term = '%'.trim($data['q']).'%';
            $query->where(fn ($q) => $q
                ->where('events.title', 'like', $term)
                ->orWhere('events.city', 'like', $term)
                ->orWhere('events.venue', 'like', $term)
                ->orWhere('events.category', 'like', $term)
                ->orWhereHas('production', fn ($p) => $p->where('name', 'like', $term))
                ->orWhereHas('artists', fn ($a) => $a->where('stage_name', 'like', $term)));
        }

        if (($data['free'] ?? false) || ($data['available'] ?? false)) {
            $query->whereHas('tickets', function ($q) use ($appId, $data, $now) {
                $q->where('tickets.app_id', $appId);
                $this->ticketInventory->constrainSellable($q, $appId, $now);

                if ($data['free'] ?? false) {
                    $q->where('tickets.price', 0);
                }
            });
        }

        $distanceEnabled = isset($data['lat'], $data['lng']);
        if ($distanceEnabled) {
            $lat = (float) $data['lat'];
            $lng = (float) $data['lng'];
            $radius = (int) ($data['radius_km'] ?? 50);
            $distanceSql = '(6371 * acos(cos(radians(?)) * cos(radians(events.latitude)) * cos(radians(events.longitude) - radians(?)) + sin(radians(?)) * sin(radians(events.latitude))))';
            $query->whereNotNull('events.latitude')
                ->whereNotNull('events.longitude')
                ->select('events.*')
                ->selectRaw("{$distanceSql} AS distance_km", [$lat, $lng, $lat])
                ->whereRaw("{$distanceSql} <= ?", [$lat, $lng, $lat, $radius]);
        } elseif (($data['view'] ?? null) === 'compact') {
            $query->select([
                'events.id', 'events.app_id', 'events.production_id', 'events.title', 'events.slug',
                'events.image', 'events.start_date', 'events.end_date', 'events.city', 'events.uf',
                'events.venue', 'events.address', 'events.category', 'events.latitude', 'events.longitude',
                'events.created_at',
            ]);
        }

        switch ($data['sort'] ?? 'soonest') {
            case 'newest':
                $query->orderByDesc('events.created_at');
                break;
            case 'popular':
                $query->selectSub(
                    fn ($sub) => $sub->from('event_passes')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('event_passes.event_id', 'events.id'),
                    'popularity_score'
                )->orderByDesc('popularity_score')->orderBy('events.start_date');
                break;
            default:
                if ($distanceEnabled) {
                    $query->orderBy('distance_km');
                }
                $query->orderBy('events.start_date');
        }

        $events = $query->paginate($data['per_page'] ?? 18)->appends($request->query());
        $eventIds = $events->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->values();
        if ($eventIds->isNotEmpty()) {
            $pageTickets = Ticket::query()
                ->where('app_id', $appId)
                ->whereIn('event_id', $eventIds)
                ->get(['id', 'app_id', 'event_id', 'price', 'quantity', 'limit_date']);
            $availabilityByEvent = $this->ticketInventory->availabilityByEvent($pageTickets, $now);

            $events->getCollection()->each(function (Event $event) use ($availabilityByEvent) {
                $summary = $availabilityByEvent->get((int) $event->id, [
                    'status' => 'tickets_pending',
                    'configured_lots_count' => 0,
                    'sellable_lots_count' => 0,
                    'sellable_free_lots_count' => 0,
                ]);
                $event->setAttribute('ticket_availability_status', $summary['status']);
            });
        }

        $payload = [
            'events' => $events->toArray(),
            'context' => [
                'timezone' => $timezone,
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
                'city' => $data['city'] ?? null,
                'uf' => isset($data['uf']) ? strtoupper($data['uf']) : null,
                'period' => $data['period'] ?? null,
            ],
        ];
        Cache::put($cacheKey, $payload, now()->addSeconds(20));

        return response()->json($payload)->header('X-Peter-Cache', 'MISS');
    }

    public function publicEvent(Request $request, string $slug)
    {
        $appId = $this->context->id();
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $now = Carbon::now($timezone);
        $event = Event::query()
            ->where('app_id', $appId)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($q) => $q->where('is_private', false)->orWhereNull('is_private'))
            ->with([
                'production:id,app_id,name,slug,user_id,app_slug,logo,background,description,city,uf,instagram_url,website_url',
                'artists' => fn ($q) => $q
                    ->where('artists.app_id', $appId)
                    ->where('artists.is_published', true)
                    ->orderByDesc('event_artist.is_headliner')
                    ->orderBy('event_artist.sort_order'),
            ])
            ->firstOrFail();
        $eventEnded = $event->hasEnded($now);
        $allTickets = Ticket::query()
            ->where('app_id', $appId)
            ->where('event_id', $event->id)
            ->orderBy('created_at')
            ->get();
        $ticketStates = $this->ticketInventory->states($allTickets, $now);
        $availability = $this->ticketInventory->availability($allTickets, $now, $ticketStates);
        $event->setAttribute('ticket_availability_status', $availability['status']);
        $event->setAttribute('sellable_ticket_lots_count', $availability['sellable_lots_count']);
        $event->setAttribute('sellable_free_ticket_lots_count', $availability['sellable_free_lots_count']);

        $tickets = $allTickets->filter(fn (Ticket $ticket) => (float) $ticket->price <= 0)->values();
        $tickets->each(function (Ticket $ticket) use ($ticketStates, $eventEnded) {
            $state = $ticketStates->get((int) $ticket->id, ['remaining' => 0, 'expired' => true, 'available' => false]);
            if ($eventEnded) {
                $state['expired'] = true;
                $state['available'] = false;
            }
            foreach ($state as $key => $value) {
                $ticket->setAttribute($key, $value);
            }
        });

        $history = null;
        if ($eventEnded) {
            $validPasses = DB::table('event_passes')
                ->where('event_id', $event->id)
                ->whereNotIn('status', ['cancelled', 'refunded', 'charged_back']);
            $rating = DB::table('event_ratings')
                ->where('app_id', $appId)
                ->where('event_id', $event->id)
                ->selectRaw('ROUND(AVG(rating),1) average, COUNT(*) total')
                ->first();
            $history = [
                'participants' => (clone $validPasses)->whereNotNull('user_id')->distinct()->count('user_id'),
                'checkins' => (clone $validPasses)->whereNotNull('checked_in_at')->count(),
                'community_posts' => DB::table('event_posts')->where('app_id', $appId)->where('event_id', $event->id)->where('status', 'published')->count(),
                'artists' => $event->artists->count(),
                'rating_average' => $rating?->average ? (float) $rating->average : 0,
                'rating_total' => (int) ($rating?->total ?? 0),
            ];
        }

        return response()->json(['event' => $event, 'tickets' => $tickets, 'history' => $history]);
    }

    public function facets(Request $request)
    {
        $appId = $this->context->id();
        $now = Carbon::now(config('app.timezone', 'America/Sao_Paulo'));
        $cacheKey = 'event-facets:v2:'.$appId;
        if (($cached = Cache::get($cacheKey)) !== null) {
            return response()->json($cached)->header('X-Peter-Cache', 'HIT');
        }
        $public = fn ($q) => $q->where(fn ($privacy) => $privacy
            ->where('is_private', false)
            ->orWhereNull('is_private'));
        $active = fn ($q) => $q->where(fn ($dates) => $dates
            ->whereNull('end_date')
            ->orWhere('end_date', '>', $now));

        $cities = Event::query()
            ->where('app_id', $appId)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where($public)
            ->where($active)
            ->whereNotNull('city')
            ->selectRaw('city, uf, COUNT(*) total')
            ->groupBy('city', 'uf')
            ->orderByDesc('total')
            ->orderBy('city')
            ->limit(100)
            ->get();

        $categories = Event::query()
            ->where('app_id', $appId)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where($public)
            ->where($active)
            ->whereNotNull('category')
            ->selectRaw('category, COUNT(*) total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->orderBy('category')
            ->limit(50)
            ->get();

        $payload = ['cities' => $cities->toArray(), 'categories' => $categories->toArray()];
        Cache::put($cacheKey, $payload, now()->addMinutes(2));

        return response()->json($payload)->header('X-Peter-Cache', 'MISS');
    }

    private function periodRange(array $data, string $timezone): array
    {
        $now = Carbon::now($timezone);
        $period = $data['period'] ?? null;
        if ($period === 'custom') {
            return [
                !empty($data['from']) ? Carbon::createFromFormat('Y-m-d', $data['from'], $timezone)->startOfDay() : null,
                !empty($data['to']) ? Carbon::createFromFormat('Y-m-d', $data['to'], $timezone)->endOfDay() : null,
            ];
        }
        if (!empty($data['date'])) {
            $day = Carbon::createFromFormat('Y-m-d', $data['date'], $timezone);
            return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
        }
        if (!$period) {
            return [null, null];
        }

        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'tomorrow' => [$now->copy()->addDay()->startOfDay(), $now->copy()->addDay()->endOfDay()],
            'next7' => [$now->copy(), $now->copy()->addDays(7)->endOfDay()],
            'next30' => [$now->copy(), $now->copy()->addDays(30)->endOfDay()],
            'month' => [$now->copy()->startOfDay(), $now->copy()->endOfMonth()],
            'weekend' => $this->weekendRange($now),
            'friday' => $this->weekdayRange($now, Carbon::FRIDAY),
            'saturday' => $this->weekdayRange($now, Carbon::SATURDAY),
            'sunday' => $this->weekdayRange($now, Carbon::SUNDAY),
            default => [null, null],
        };
    }

    private function weekendRange(Carbon $now): array
    {
        if ($now->isFriday()) {
            $friday = $now->copy();
        } elseif ($now->isSaturday() || $now->isSunday()) {
            $friday = $now->copy()->previous(Carbon::FRIDAY);
        } else {
            $friday = $now->copy()->next(Carbon::FRIDAY);
        }
        $from = $friday->copy()->startOfDay();
        if ($now->betweenIncluded($from, $friday->copy()->addDays(2)->endOfDay())) {
            $from = $now->copy();
        }
        return [$from, $friday->copy()->addDays(2)->endOfDay()];
    }

    private function weekdayRange(Carbon $now, int $weekday): array
    {
        $day = $now->dayOfWeek === $weekday ? $now->copy() : $now->copy()->next($weekday);
        $from = $day->copy()->startOfDay();
        if ($now->isSameDay($day)) {
            $from = $now->copy();
        }
        return [$from, $day->copy()->endOfDay()];
    }
}
