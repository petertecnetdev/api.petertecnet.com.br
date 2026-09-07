<?php

namespace App\Domain\Events\Services;

use App\Models\EcosystemAuditLog;
use App\Models\Establishment;
use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdminEstablishmentEventService
{
    public function __construct(private readonly EventDuplicationService $duplicator) {}

    public function index(Establishment $establishment, int $appId): array
    {
        $this->assertApplicationLinked($establishment, $appId);

        $events = Event::query()
            ->where('production_id', $establishment->id)
            ->where('app_id', $appId)
            ->withCount(['tickets', 'artists'])
            ->orderByDesc('start_date')
            ->limit(100)
            ->get([
                'id', 'app_id', 'app_slug', 'production_id', 'title', 'slug', 'start_date', 'end_date',
                'venue', 'city', 'uf', 'is_published', 'is_cancelled', 'image',
            ]);

        return [
            'establishment' => [
                'id' => $establishment->id,
                'name' => $establishment->fantasy ?: $establishment->name,
                'user_id' => $establishment->user_id,
            ],
            'events' => $events,
        ];
    }

    public function duplicate(
        Establishment $establishment,
        Event $event,
        int $appId,
        string $date,
        ?int $actorId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $this->assertApplicationLinked($establishment, $appId);

        if ((int) $event->production_id !== (int) $establishment->id || (int) $event->app_id !== $appId) {
            throw ValidationException::withMessages([
                'event' => ['O evento informado não pertence a este estabelecimento e aplicação.'],
            ]);
        }

        $duplicate = $this->duplicator->duplicate($event, $date, $appId, $event->app_slug);

        EcosystemAuditLog::create([
            'user_id' => $actorId,
            'action' => 'event.duplicated_from_admin_center',
            'entity_type' => Event::class,
            'entity_id' => $duplicate->id,
            'before' => ['source_event_id' => $event->id],
            'after' => [
                'duplicate_event_id' => $duplicate->id,
                'source_event_id' => $event->id,
                'production_id' => $establishment->id,
                'app_id' => $appId,
                'new_date' => $date,
            ],
            'ip' => $ip,
            'user_agent' => Str::limit((string) $userAgent, 1000, ''),
        ]);

        return [
            'message' => 'Evento duplicado como rascunho pelo Admin Center.',
            'event' => $duplicate,
            'copied' => [
                'tickets' => $duplicate->tickets_count,
                'artists' => $duplicate->artists->count(),
            ],
        ];
    }

    public function deleteMany(
        Establishment $establishment,
        int $appId,
        array $eventIds,
        ?int $actorId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $this->assertApplicationLinked($establishment, $appId);

        $ids = collect($eventIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages([
                'event_ids' => ['Selecione pelo menos um evento para excluir.'],
            ]);
        }

        $events = Event::query()
            ->where('production_id', $establishment->id)
            ->where('app_id', $appId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'title', 'image', 'production_id', 'app_id']);

        if ($events->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'event_ids' => ['Um ou mais eventos selecionados não pertencem a este estabelecimento e aplicação ou já foram removidos. Atualize a lista e tente novamente.'],
            ]);
        }

        $images = $events
            ->pluck('image')
            ->filter(fn ($path) => is_string($path) && str_starts_with($path, 'images/events/'))
            ->values()
            ->all();

        DB::transaction(function () use ($events, $actorId, $ip, $userAgent, $establishment, $appId): void {
            foreach ($events as $event) {
                $snapshot = [
                    'id' => $event->id,
                    'title' => $event->title,
                    'production_id' => $event->production_id,
                    'app_id' => $event->app_id,
                ];

                $event->delete();

                EcosystemAuditLog::create([
                    'user_id' => $actorId,
                    'action' => 'event.deleted_from_admin_center',
                    'entity_type' => Event::class,
                    'entity_id' => $event->id,
                    'before' => $snapshot,
                    'after' => [
                        'deleted' => true,
                        'bulk_operation' => true,
                        'production_id' => $establishment->id,
                        'app_id' => $appId,
                    ],
                    'ip' => $ip,
                    'user_agent' => Str::limit((string) $userAgent, 1000, ''),
                ]);
            }
        });

        foreach ($images as $path) {
            Storage::disk('public')->delete($path);
        }

        return [
            'message' => $ids->count() === 1
                ? 'Evento excluído com sucesso.'
                : $ids->count().' eventos excluídos com sucesso.',
            'deleted_count' => $ids->count(),
            'deleted_ids' => $ids->all(),
        ];
    }

    private function assertApplicationLinked(Establishment $establishment, int $appId): void
    {
        $linked = (int) $establishment->app_id === $appId
            || $establishment->applications()->where('applications.id', $appId)->exists();

        if (! $linked) {
            throw ValidationException::withMessages([
                'app_id' => ['A aplicação informada não está vinculada a este estabelecimento.'],
            ]);
        }
    }
}
