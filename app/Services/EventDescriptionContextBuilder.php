<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventItem;
use App\Models\Production;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class EventDescriptionContextBuilder
{
    public function __construct(
        private readonly ApplicationContext $applicationContext,
        private readonly AiEditorialProfileService $editorialProfiles,
    ) {}

    public function build(array $inputContext, ?User $user = null): array
    {
        $appId = $this->applicationContext->id();
        $eventId = $this->numericContext($inputContext, ['entityId', 'eventId', 'event_id']);
        $productionId = $this->numericContext($inputContext, ['production_id', 'productionId']);

        $event = null;
        $production = null;

        if ($eventId > 0) {
            $event = Event::query()
                ->where('app_id', $appId)
                ->with([
                    'production',
                    'tickets' => fn ($query) => $query->orderBy('price')->orderBy('id'),
                    'artists' => fn ($query) => $query->orderByDesc('event_artist.is_headliner')->orderBy('event_artist.sort_order'),
                ])
                ->find($eventId);

            if ($event) {
                $production = $event->production;
                $productionId = (int) $event->production_id;
            }
        }

        if (! $production && $productionId > 0) {
            $production = Production::query()
                ->where('app_id', $appId)
                ->find($productionId);
        }

        if ($production && $user && ! $this->canManageProduction($production, $user)) {
            abort(403, 'Você não pode usar os dados editoriais desta produção.');
        }

        $current = $this->currentFacts($event, $production, $inputContext);

        $historyQuery = Event::query()
            ->where('app_id', $appId)
            ->where('production_id', $productionId > 0 ? $productionId : -1)
            ->whereNotNull('description')
            ->where('description', '!=', '');

        if ($event) {
            $historyQuery->whereKeyNot($event->id);
        }

        $history = $historyQuery
            ->orderByDesc('start_date')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get(['id', 'title', 'description', 'start_date', 'category']);

        $profile = $productionId > 0
            ? $this->editorialProfiles->forProduction($appId, $productionId, $history)
            : ['traits' => [], 'avoid_phrases' => []];

        $historicalTexts = [];
        foreach ($history->take(8)->values() as $index => $reference) {
            $referenceText = trim((string) $reference->description);
            if ($referenceText === '') continue;

            $historicalTexts[] = $referenceText;
            $current['historical_style_' . ($index + 1)] = mb_substr(
                'Título anterior: ' . trim((string) $reference->title) . "\n" .
                'Descrição anterior: ' . $referenceText,
                0,
                900,
            );
        }

        if (($profile['avoid_phrases'] ?? []) !== []) {
            $current['editorial_avoid_phrases'] = mb_substr(
                implode(' | ', (array) $profile['avoid_phrases']),
                0,
                1200,
            );
        }

        if (($profile['traits'] ?? []) !== []) {
            $traits = (array) $profile['traits'];
            $current['editorial_profile'] = mb_substr(
                'média histórica de ' . (int) ($traits['average_words'] ?? 0) . ' palavras; ' .
                'média de ' . (string) ($traits['average_paragraphs'] ?? 0) . ' parágrafos; ' .
                'objetivo: ' . (string) ($traits['goal'] ?? ''),
                0,
                500,
            );
        }

        return [
            'context' => array_slice(array_filter(
                $current,
                static fn ($value) => $value !== null && trim((string) $value) !== '',
            ), 0, 24, true),
            'historical_texts' => $historicalTexts,
            'event' => $event,
            'production' => $production,
            'event_id' => $event?->id ?: ($eventId > 0 ? $eventId : null),
            'production_id' => $productionId > 0 ? $productionId : null,
        ];
    }

    private function currentFacts(?Event $event, ?Production $production, array $inputContext): array
    {
        $facts = [];

        if ($event) {
            $facts = [
                'entityId' => (string) $event->id,
                'production_id' => (string) $event->production_id,
                'production_name' => (string) ($production?->name ?? ''),
                'production_description' => mb_substr(trim((string) ($production?->description ?? '')), 0, 500),
                'venue' => (string) ($event->venue ?? ''),
                'city' => (string) ($event->city ?? ''),
                'uf' => (string) ($event->uf ?? ''),
                'category' => (string) ($event->category ?? ''),
                'event_format' => (string) ($event->event_format ?? ''),
                'start_date' => $event->start_date?->format('Y-m-d H:i:s') ?? '',
                'end_date' => $event->end_date?->format('Y-m-d H:i:s') ?? '',
            ];

            $tickets = $event->tickets
                ->filter(fn ($ticket) => (int) $ticket->quantity > 0)
                ->take(6)
                ->map(function ($ticket) {
                    $price = (float) $ticket->price;
                    $name = trim((string) $ticket->name);
                    if ($price <= 0) {
                        $name = trim((string) preg_replace('/\s*[—-]?\s*(free|gratuito|grátis|gratis)\s*$/iu', '', $name));
                    }
                    return $name . ' — ' .
                        ($price <= 0 ? 'gratuito' : 'R$ ' . number_format($price, 2, ',', '.'));
                })
                ->filter()
                ->implode('; ');

            if ($tickets !== '') {
                $facts['ticket_options'] = mb_substr($tickets, 0, 500);
            }

            $artists = $event->artists
                ->filter(function ($artist) {
                    $status = mb_strtolower(trim((string) ($artist->pivot?->status ?? '')));
                    return ! in_array($status, ['rejected', 'declined', 'cancelled', 'canceled'], true);
                })
                ->take(8)
                ->map(function ($artist) {
                    $name = trim((string) ($artist->stage_name ?: $artist->name ?? ''));
                    $participation = trim((string) ($artist->pivot?->participation_type ?? ''));
                    return $participation !== '' ? $name . ' (' . $participation . ')' : $name;
                })
                ->filter()
                ->implode('; ');

            if ($artists !== '') {
                $facts['artists'] = mb_substr($artists, 0, 500);
            }

            $items = EventItem::query()
                ->where('app_id', $event->app_id)
                ->where('event_id', $event->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->limit(8)
                ->pluck('name')
                ->filter()
                ->implode('; ');

            if ($items !== '') {
                $facts['event_items'] = mb_substr($items, 0, 500);
            }
        } elseif ($production) {
            $facts = [
                'production_id' => (string) $production->id,
                'production_name' => (string) $production->name,
                'production_description' => mb_substr(trim((string) ($production->description ?? '')), 0, 500),
            ];
        }

        $safeOverlay = [
            'production_id', 'productionId', 'venue', 'city', 'uf', 'category',
            'event_format', 'start_date', 'end_date', 'event_start', 'event_end',
            'address', 'title',
        ];

        foreach ($safeOverlay as $key) {
            if (! array_key_exists($key, $inputContext)) continue;
            $value = trim((string) $inputContext[$key]);
            if ($value === '') continue;
            $facts[$key] = mb_substr($value, 0, 500);
        }

        $startValue = (string) ($facts['start_date'] ?? $facts['event_start'] ?? '');
        if ($startValue !== '') {
            try {
                $day = Carbon::parse($startValue, config('app.timezone', 'America/Sao_Paulo'))->isoWeekday();
                $facts['weekday'] = [
                    1 => 'segunda-feira',
                    2 => 'terça-feira',
                    3 => 'quarta-feira',
                    4 => 'quinta-feira',
                    5 => 'sexta-feira',
                    6 => 'sábado',
                    7 => 'domingo',
                ][$day] ?? '';
            } catch (\Throwable) {
            }
        }

        $richSignals = 0;
        foreach (['artists', 'ticket_options', 'event_items', 'category', 'production_description'] as $key) {
            if (trim((string) ($facts[$key] ?? '')) !== '') $richSignals++;
        }
        $facts['content_density'] = $richSignals >= 3 ? 'rich' : ($richSignals >= 1 ? 'medium' : 'lean');

        return $facts;
    }

    private function numericContext(array $context, array $keys): int
    {
        foreach ($keys as $key) {
            if (! isset($context[$key])) continue;
            $value = (int) $context[$key];
            if ($value > 0) return $value;
        }
        return 0;
    }

    private function canManageProduction(Production $production, User $user): bool
    {
        if ((int) $production->user_id === (int) $user->id) return true;
        if (method_exists($user, 'hasProfile') && $user->hasProfile('Administrador')) return true;
        return strtolower(trim((string) $user->email)) === 'petertecnet@gmail.com';
    }
}
