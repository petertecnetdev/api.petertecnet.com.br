<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Event;
use App\Services\Admin\EstablishmentEventTicketAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstablishmentEventController extends Controller
{
    public function __construct(private readonly EstablishmentEventTicketAnalyticsService $ticketAnalytics)
    {
    }

    public function index(Request $request, Establishment $establishment): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
        ]);

        $events = Event::query()
            ->select([
                'id',
                'app_id',
                'production_id',
                'title',
                'slug',
                'start_date',
                'end_date',
                'venue',
                'city',
                'uf',
                'is_published',
                'is_approved',
                'is_cancelled',
                'is_private',
            ])
            ->where('production_id', $establishment->id)
            ->when(
                isset($data['app_id']),
                fn ($query) => $query->where('app_id', (int) $data['app_id'])
            )
            ->withCount('artists')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        $events = $this->ticketAnalytics->attachSummaries($events);

        return response()->json([
            'establishment' => [
                'id' => $establishment->id,
                'name' => $establishment->name,
                'fantasy' => $establishment->fantasy,
                'slug' => $establishment->slug,
                'city' => $establishment->city,
                'uf' => $establishment->uf,
            ],
            'events' => $events,
        ]);
    }

    public function tickets(Request $request, Establishment $establishment, Event $event): JsonResponse
    {
        abort_unless((int) $event->production_id === (int) $establishment->id, 404);

        $data = $request->validate([
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
        ]);

        if (isset($data['app_id']) && (int) $event->app_id !== (int) $data['app_id']) {
            abort(404);
        }

        return response()->json([
            'establishment' => [
                'id' => $establishment->id,
                'name' => $establishment->name,
                'fantasy' => $establishment->fantasy,
                'slug' => $establishment->slug,
                'city' => $establishment->city,
                'uf' => $establishment->uf,
            ],
            'event' => [
                'id' => $event->id,
                'app_id' => $event->app_id,
                'production_id' => $event->production_id,
                'title' => $event->title,
                'slug' => $event->slug,
                'start_date' => $event->start_date?->toIso8601String(),
                'end_date' => $event->end_date?->toIso8601String(),
                'venue' => $event->venue,
                'city' => $event->city,
                'uf' => $event->uf,
                'is_published' => (bool) $event->is_published,
                'is_cancelled' => (bool) $event->is_cancelled,
            ],
            ...$this->ticketAnalytics->eventTicketDetails($event),
        ]);
    }
}
