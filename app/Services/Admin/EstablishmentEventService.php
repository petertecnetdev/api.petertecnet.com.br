<?php

namespace App\Services\Admin;

use App\Models\Establishment;
use App\Models\Event;
use Illuminate\Support\Collection;

final class EstablishmentEventService
{
    public function __construct(private readonly EstablishmentEventTicketAnalyticsService $ticketAnalytics)
    {
    }

    public function listing(Establishment $establishment, ?int $appId = null): array
    {
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
            ->when($appId !== null, fn ($query) => $query->where('app_id', $appId))
            ->withCount('artists')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        return [
            'establishment' => $this->establishmentPayload($establishment),
            'events' => $this->ticketAnalytics->attachSummaries($events),
        ];
    }

    public function ticketDetails(Establishment $establishment, Event $event, ?int $appId = null): array
    {
        abort_unless((int) $event->production_id === (int) $establishment->id, 404);
        if ($appId !== null && (int) $event->app_id !== $appId) {
            abort(404);
        }

        return [
            'establishment' => $this->establishmentPayload($establishment),
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
        ];
    }

    private function establishmentPayload(Establishment $establishment): array
    {
        return [
            'id' => $establishment->id,
            'name' => $establishment->name,
            'fantasy' => $establishment->fantasy,
            'slug' => $establishment->slug,
            'city' => $establishment->city,
            'uf' => $establishment->uf,
        ];
    }
}
