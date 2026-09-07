<?php

namespace App\Domain\Events\Services;

use App\Models\EcosystemAuditLog;
use App\Models\Establishment;
use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

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

    public function bulkDelete(
        Establishment $establishment,
        int $appId,
        array $eventIds,
        ?int $actorId,
        ?string $ip,
        ?string $userAgent,
        ?callable $onProgress = null,
    ): array {
        $this->assertApplicationLinked($establishment, $appId);

        $ids = collect($eventIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
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
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy(fn (Event $event) => (int) $event->id);

        $total = $ids->count();
        $deletedIds = [];
        $failures = [];

        foreach ($ids as $index => $eventId) {
            /** @var Event|null $event */
            $event = $events->get($eventId);
            $title = $event?->title ?: "Evento #{$eventId}";
            $status = 'deleted';

            if (! $event) {
                $status = 'failed';
                $failures[] = [
                    'id' => $eventId,
                    'title' => $title,
                    'message' => 'Evento não encontrado neste estabelecimento e aplicação.',
                ];
            } else {
                try {
                    DB::transaction(function () use ($event, $establishment, $appId, $actorId, $ip, $userAgent): void {
                        $before = [
                            'id' => $event->id,
                            'title' => $event->title,
                            'start_date' => optional($event->start_date)->toIso8601String(),
                            'production_id' => $event->production_id,
                            'app_id' => $event->app_id,
                            'is_published' => (bool) $event->is_published,
                            'is_cancelled' => (bool) $event->is_cancelled,
                        ];

                        $event->artists()->detach();
                        $event->delete();

                        EcosystemAuditLog::create([
                            'user_id' => $actorId,
                            'action' => 'event.deleted_from_admin_center_bulk',
                            'entity_type' => Event::class,
                            'entity_id' => $event->id,
                            'before' => $before,
                            'after' => [
                                'deleted' => true,
                                'bulk' => true,
                                'production_id' => $establishment->id,
                                'app_id' => $appId,
                            ],
                            'ip' => $ip,
                            'user_agent' => Str::limit((string) $userAgent, 1000, ''),
                        ]);
                    });

                    $deletedIds[] = $eventId;
                } catch (Throwable $exception) {
                    report($exception);
                    $status = 'failed';
                    $failures[] = [
                        'id' => $eventId,
                        'title' => $title,
                        'message' => 'Não foi possível excluir o evento. Há vínculos ou dados relacionados protegendo este registro.',
                    ];
                }
            }

            $processed = $index + 1;
            if ($onProgress) {
                $onProgress([
                    'processed_count' => $processed,
                    'total_count' => $total,
                    'remaining_count' => max($total - $processed, 0),
                    'deleted_count' => count($deletedIds),
                    'failed_count' => count($failures),
                    'event_id' => $eventId,
                    'event_title' => $title,
                    'status' => $status,
                ]);
            }
        }

        $deletedCount = count($deletedIds);
        $failedCount = count($failures);

        return [
            'message' => $failedCount > 0
                ? "{$deletedCount} evento(s) excluído(s). {$failedCount} não puderam ser excluídos."
                : "{$deletedCount} evento(s) excluído(s) com sucesso.",
            'requested_count' => $total,
            'processed_count' => $total,
            'deleted_count' => $deletedCount,
            'failed_count' => $failedCount,
            'deleted_ids' => $deletedIds,
            'failures' => $failures,
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
