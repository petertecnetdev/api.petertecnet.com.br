<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CutinappDiscoveryController extends Controller
{
    private const APP = 'cutinapp';

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
        ], [
            'period.in' => 'Escolha um período de busca válido.',
            'to.after_or_equal' => 'A data final precisa ser igual ou posterior à data inicial.',
            'date.date_format' => 'Informe a data no formato correto.',
            'lat.required_with' => 'Latitude e longitude precisam ser enviadas juntas.',
            'lng.required_with' => 'Latitude e longitude precisam ser enviadas juntas.',
        ]);

        $appId = $this->applicationId();
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $now = Carbon::now($timezone);
        [$from, $to] = $this->periodRange($data, $timezone);

        $query = Event::query()
            ->where('events.app_id', $appId)
            ->where('events.app_slug', self::APP)
            ->where('events.is_published', true)
            ->where('events.is_cancelled', false)
            ->where('events.is_private', false)
            ->where('events.end_date', '>', $now)
            ->with([
                'production:id,app_id,name,slug,user_id,app_slug,logo,city,uf',
                'artists:id,app_id,slug,stage_name,photo',
            ])
            ->withCount(['tickets as ticket_lots_count' => fn ($q) => $q->where('app_id', $appId)])
            ->withCount(['tickets as free_ticket_lots_count' => fn ($q) => $q->where('app_id', $appId)->where('price', 0)]);

        if (! empty($data['city'])) $query->whereRaw('LOWER(events.city) = LOWER(?)', [trim($data['city'])]);
        if (! empty($data['uf'])) $query->where('events.uf', strtoupper($data['uf']));
        if (! empty($data['category'])) $query->where('events.category', $data['category']);
        if (! empty($data['production_id'])) $query->where('events.production_id', $data['production_id']);
        if (! empty($data['artist_id'])) $query->whereHas('artists', fn ($q) => $q->where('cutinapp_artists.id', $data['artist_id']));

        // "Hoje" inclui eventos que começaram antes e continuam acontecendo agora.
        // Todos os períodos futuros são orientados pela data de início: um evento de hoje
        // que atravessa a madrugada não deve aparecer como um evento de "Amanhã".
        if ($from || $to) {
            $isToday = ($data['period'] ?? null) === 'today' && empty($data['date']);
            if ($isToday) {
                if ($from) $query->where('events.end_date', '>=', $from);
                if ($to) $query->where('events.start_date', '<=', $to);
            } else {
                if ($from) $query->where('events.start_date', '>=', $from);
                if ($to) $query->where('events.start_date', '<=', $to);
            }
        }

        if (! empty($data['q'])) {
            $term = '%' . trim($data['q']) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('events.title', 'like', $term)
                    ->orWhere('events.city', 'like', $term)
                    ->orWhere('events.venue', 'like', $term)
                    ->orWhere('events.category', 'like', $term)
                    ->orWhereHas('production', fn ($p) => $p->where('name', 'like', $term))
                    ->orWhereHas('artists', fn ($a) => $a->where('stage_name', 'like', $term));
            });
        }

        if (($data['free'] ?? false) || ($data['available'] ?? false)) {
            $query->whereHas('tickets', function ($q) use ($appId, $data, $now) {
                $q->where('tickets.app_id', $appId)
                    ->where('tickets.quantity', '>', 0)
                    ->where(fn ($d) => $d->whereNull('tickets.limit_date')->orWhere('tickets.limit_date', '>', $now))
                    ->whereRaw('tickets.quantity > (SELECT COUNT(*) FROM event_passes WHERE event_passes.ticket_id = tickets.id)');
                if ($data['free'] ?? false) $q->where('tickets.price', 0);
            });
        }

        $distanceEnabled = isset($data['lat'], $data['lng']);
        if ($distanceEnabled) {
            $lat = (float) $data['lat'];
            $lng = (float) $data['lng'];
            $radius = (int) ($data['radius_km'] ?? 50);
            $distanceSql = '(6371 * acos(cos(radians(?)) * cos(radians(events.latitude)) * cos(radians(events.longitude) - radians(?)) + sin(radians(?)) * sin(radians(events.latitude))))';
            $query->whereNotNull('events.latitude')->whereNotNull('events.longitude')
                ->select('events.*')
                ->selectRaw("{$distanceSql} AS distance_km", [$lat, $lng, $lat])
                ->whereRaw("{$distanceSql} <= ?", [$lat, $lng, $lat, $radius]);
        }

        switch ($data['sort'] ?? 'soonest') {
            case 'newest':
                $query->orderByDesc('events.created_at');
                break;
            case 'popular':
                $query->selectSub(function ($sub) {
                    $sub->from('event_passes')->selectRaw('COUNT(*)')->whereColumn('event_passes.event_id', 'events.id');
                }, 'popularity_score')->orderByDesc('popularity_score')->orderBy('events.start_date');
                break;
            default:
                if ($distanceEnabled) $query->orderBy('distance_km');
                $query->orderBy('events.start_date');
        }

        $page = $query->paginate($data['per_page'] ?? 24)->appends($request->query());
        return response()->json([
            'events' => $page,
            'context' => [
                'timezone' => $timezone,
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
                'city' => $data['city'] ?? null,
                'uf' => isset($data['uf']) ? strtoupper($data['uf']) : null,
                'period' => $data['period'] ?? null,
            ],
        ]);
    }

    public function publicEvent(Request $request, string $slug)
    {
        $appId = $this->applicationId();
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $now = Carbon::now($timezone);

        $event = Event::query()
            ->where('app_id', $appId)
            ->where('app_slug', self::APP)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where('is_private', false)
            ->with([
                'production:id,app_id,name,slug,user_id,app_slug,logo,background,description,city,uf,instagram_url,website_url',
                'artists' => fn ($q) => $q->where('cutinapp_artists.app_id', $appId)
                    ->where('cutinapp_artists.is_published', true)
                    ->orderByDesc('cutinapp_event_artist.is_headliner')
                    ->orderBy('cutinapp_event_artist.sort_order'),
            ])
            ->firstOrFail();

        $tickets = Ticket::query()
            ->where('app_id', $appId)
            ->where('event_id', $event->id)
            ->where('price', 0)
            ->withCount('passes')
            ->orderBy('created_at')
            ->get()
            ->map(function (Ticket $ticket) use ($now) {
                $remaining = max(0, (int) $ticket->quantity - (int) $ticket->passes_count);
                $expired = $ticket->limit_date && $now->greaterThan(Carbon::parse($ticket->limit_date, config('app.timezone', 'America/Sao_Paulo')));
                $ticket->setAttribute('remaining', $remaining);
                $ticket->setAttribute('available', $remaining > 0 && ! $expired);
                $ticket->setAttribute('expired', (bool) $expired);
                return $ticket;
            });

        $event->setAttribute('has_ended', $event->end_date ? Carbon::parse($event->end_date, $timezone)->lte($now) : false);
        $event->setAttribute('is_happening_now', $event->start_date && $event->end_date
            ? Carbon::parse($event->start_date, $timezone)->lte($now) && Carbon::parse($event->end_date, $timezone)->gt($now)
            : false);

        return response()->json(['event' => $event, 'tickets' => $tickets]);
    }

    public function facets(Request $request)
    {
        $appId = $this->applicationId();
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $now = Carbon::now($timezone);
        $cities = Event::query()
            ->where('app_id', $appId)->where('app_slug', self::APP)->where('is_published', true)
            ->where('is_cancelled', false)->where('is_private', false)->where('end_date', '>', $now)->whereNotNull('city')
            ->selectRaw('city, uf, COUNT(*) total')->groupBy('city', 'uf')->orderByDesc('total')->orderBy('city')->limit(100)->get();
        $categories = Event::query()
            ->where('app_id', $appId)->where('app_slug', self::APP)->where('is_published', true)
            ->where('is_cancelled', false)->where('is_private', false)->where('end_date', '>', $now)->whereNotNull('category')
            ->selectRaw('category, COUNT(*) total')->groupBy('category')->orderByDesc('total')->limit(50)->get();
        return response()->json(['cities' => $cities, 'categories' => $categories, 'timezone' => $timezone]);
    }

    private function periodRange(array $data, string $timezone): array
    {
        $now = Carbon::now($timezone);
        $period = $data['period'] ?? null;
        if ($period === 'custom') {
            $from = ! empty($data['from']) ? Carbon::createFromFormat('Y-m-d', $data['from'], $timezone)->startOfDay() : null;
            $to = ! empty($data['to']) ? Carbon::createFromFormat('Y-m-d', $data['to'], $timezone)->endOfDay() : null;
            return [$from, $to];
        }
        if (! empty($data['date'])) {
            $day = Carbon::createFromFormat('Y-m-d', $data['date'], $timezone);
            return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
        }
        if (! $period) return [null, null];

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
        if ($now->isSameDay($day)) $from = $now->copy();
        return [$from, $day->copy()->endOfDay()];
    }

    private function applicationId(): int
    {
        $app = Application::where('slug', self::APP)->where('is_active', true)->first();
        abort_unless($app, 503, 'A Cutinapp não está registrada corretamente na API.');
        return (int) $app->id;
    }
}
