<?php

namespace App\Domain\Events\Services;

use App\Models\EcosystemAuditLog;
use App\Models\Establishment;
use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;
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

    public function show(Establishment $establishment, Event $event, int $appId): array
    {
        $this->assertOwnedEvent($establishment, $event, $appId);

        return [
            'event' => $event->fresh([
                'production:id,name,fantasy,slug,user_id,city,uf,address,contact_email,contact_phone',
            ]),
        ];
    }

    public function update(
        Establishment $establishment,
        Event $event,
        int $appId,
        array $data,
        ?int $actorId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $this->assertOwnedEvent($establishment, $event, $appId);

        $before = $this->auditSnapshot($event);
        $oldImage = $event->image;
        $generatedImage = null;

        if (! empty($data['image_data_uri'])) {
            $generatedImage = $this->storeDataUriImage((string) $data['image_data_uri']);
            $data['image'] = $generatedImage;
        }
        unset($data['image_data_uri']);

        if (isset($data['title']) && trim((string) $data['title']) !== trim((string) $event->title)) {
            $data['slug'] = $this->uniqueSlug((string) $data['title'], $event->id);
        }

        try {
            DB::transaction(function () use ($event, $data, $actorId, $ip, $userAgent, $before): void {
                $event->update($data);
                $event->refresh();

                EcosystemAuditLog::create([
                    'user_id' => $actorId,
                    'action' => 'event.updated_from_admin_center',
                    'entity_type' => Event::class,
                    'entity_id' => $event->id,
                    'before' => $before,
                    'after' => $this->auditSnapshot($event),
                    'ip' => $ip,
                    'user_agent' => Str::limit((string) $userAgent, 1000, ''),
                ]);
            });
        } catch (Throwable $exception) {
            if ($generatedImage) {
                Storage::disk('public')->delete($generatedImage);
            }
            throw $exception;
        }

        if ($generatedImage && $oldImage && $oldImage !== $generatedImage && str_starts_with($oldImage, 'images/events/')) {
            Storage::disk('public')->delete($oldImage);
        }

        return [
            'message' => $generatedImage
                ? 'Evento e nova imagem gerada por IA atualizados com sucesso.'
                : 'Evento atualizado com sucesso pelo Admin Center.',
            'event' => $event->fresh([
                'production:id,name,fantasy,slug,user_id,city,uf,address,contact_email,contact_phone',
            ]),
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

    private function assertOwnedEvent(Establishment $establishment, Event $event, int $appId): void
    {
        $this->assertApplicationLinked($establishment, $appId);

        if ((int) $event->production_id !== (int) $establishment->id || (int) $event->app_id !== $appId) {
            throw ValidationException::withMessages([
                'event' => ['O evento informado não pertence a este estabelecimento e aplicação.'],
            ]);
        }
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

    private function auditSnapshot(Event $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'category' => $event->category,
            'event_format' => $event->event_format,
            'start_date' => optional($event->start_date)->toIso8601String(),
            'end_date' => optional($event->end_date)->toIso8601String(),
            'venue' => $event->venue,
            'address' => $event->address,
            'city' => $event->city,
            'uf' => $event->uf,
            'online_platform' => $event->online_platform,
            'online_url' => $event->online_url,
            'max_attendees' => $event->max_attendees,
            'contact_email' => $event->contact_email,
            'contact_phone' => $event->contact_phone,
            'is_featured' => (bool) $event->is_featured,
            'is_published' => (bool) $event->is_published,
            'is_approved' => (bool) $event->is_approved,
            'is_cancelled' => (bool) $event->is_cancelled,
            'is_private' => (bool) $event->is_private,
            'requires_approval' => (bool) $event->requires_approval,
            'approval_message' => $event->approval_message,
            'image' => $event->image,
        ];
    }

    private function storeDataUriImage(string $dataUri): string
    {
        if (! preg_match('/^data:image\/(?:png|jpe?g|webp);base64,(.+)$/is', $dataUri, $matches)) {
            throw ValidationException::withMessages([
                'image_data_uri' => ['A imagem gerada pela IA está em um formato inválido.'],
            ]);
        }

        $binary = base64_decode(str_replace(' ', '+', $matches[1]), true);
        if ($binary === false || strlen($binary) === 0) {
            throw ValidationException::withMessages([
                'image_data_uri' => ['Não foi possível interpretar a imagem gerada pela IA.'],
            ]);
        }
        if (strlen($binary) > 8 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'image_data_uri' => ['A imagem gerada pela IA excedeu 8 MB.'],
            ]);
        }

        try {
            $encoded = Image::make($binary)->orientate()->fit(850, 450)->encode('webp', 85);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'image_data_uri' => ['A imagem gerada pela IA não pôde ser processada.'],
            ]);
        }

        $path = 'images/events/'.Str::uuid().'.webp';
        Storage::disk('public')->put($path, (string) $encoded);

        return $path;
    }

    private function uniqueSlug(string $source, int $ignoreId): string
    {
        $base = Str::slug($source) ?: Str::random(12);
        $slug = $base;
        $suffix = 2;

        while (Event::query()->where('slug', $slug)->where('id', '!=', $ignoreId)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
