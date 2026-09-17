<?php

namespace App\Domain\Events\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class EventMediaLibraryService
{
    public function paginate(int $appId, User $user, array $filters): LengthAwarePaginatorContract
    {
        $perPage = (int) ($filters['per_page'] ?? 24);
        $page = max(1, LengthAwarePaginator::resolveCurrentPage());
        $requestedType = trim((string) ($filters['type'] ?? ''));
        $term = Str::lower(trim((string) ($filters['q'] ?? '')));

        $query = Event::query()
            ->where('app_id', $appId)
            ->whereHas('production', fn ($production) => $production->where('app_id', $appId))
            ->with('production:id,app_id,name,slug,user_id,app_slug');

        if (! $this->canManageAll($user)) {
            $query->whereHas('production', fn ($production) => $production
                ->where('app_id', $appId)
                ->where('user_id', $user->id));
        }

        if (! empty($filters['production_id'])) {
            $query->where('production_id', (int) $filters['production_id']);
        }

        if ($requestedType === 'image') {
            $query->whereNotNull('image')->where('image', '<>', '');
        } elseif ($requestedType === 'audio') {
            $query->whereNotNull('additional_info');
        } else {
            $query->where(function ($eventQuery): void {
                $eventQuery
                    ->where(function ($imageQuery): void {
                        $imageQuery->whereNotNull('image')->where('image', '<>', '');
                    })
                    ->orWhereNotNull('additional_info');
            });
        }

        $events = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $items = collect();

        foreach ($events as $event) {
            if (($requestedType === '' || $requestedType === 'image') && filled($event->image)) {
                $items->push($this->serializeEventCover($event));
            }

            if ($requestedType === '' || $requestedType === 'audio') {
                foreach ($this->uploadedSoundtrackItems($event) as $soundtrackItem) {
                    $items->push($this->serializeSoundtrackItem($event, $soundtrackItem));
                }
            }
        }

        if ($term !== '') {
            $items = $items->filter(function (array $item) use ($term): bool {
                $haystack = Str::lower(implode(' ', array_filter([
                    $item['media_title'] ?? null,
                    $item['event_title'] ?? null,
                    $item['production_name'] ?? null,
                ])));

                return str_contains($haystack, $term);
            })->values();
        }

        $items = $items
            ->sortByDesc(fn (array $item) => (string) ($item['updated_at'] ?? $item['created_at'] ?? ''))
            ->values();

        $total = $items->count();
        $pageItems = $items->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            $pageItems,
            $total,
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => array_filter([
                    'q' => $filters['q'] ?? null,
                    'production_id' => $filters['production_id'] ?? null,
                    'type' => $filters['type'] ?? null,
                    'per_page' => $filters['per_page'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''),
            ],
        );
    }

    public function find(int $appId, User $user, int $eventId): array
    {
        return $this->serializeEventCover($this->ownedMediaEvent($appId, $user, $eventId));
    }

    public function download(int $appId, User $user, int $eventId)
    {
        $event = $this->ownedMediaEvent($appId, $user, $eventId);
        $path = ltrim((string) $event->image, '/');

        return $this->downloadStoredPath(
            $path,
            (Str::slug((string) $event->title) ?: 'evento').'-capa',
            'webp',
        );
    }

    public function downloadSoundtrackItem(int $appId, User $user, int $eventId, string $itemId)
    {
        $event = $this->ownedEvent($appId, $user, $eventId);
        $item = collect($this->uploadedSoundtrackItems($event))
            ->first(fn (array $candidate) => hash_equals((string) ($candidate['id'] ?? ''), $itemId));

        abort_unless(is_array($item), 404, 'Mídia não encontrada neste evento.');

        $title = trim((string) ($item['title'] ?? ''));
        $filename = Str::slug($title) ?: (Str::slug((string) $event->title) ?: 'evento').'-audio';

        return $this->downloadStoredPath(
            ltrim((string) ($item['path'] ?? ''), '/'),
            $filename,
            'mp3',
        );
    }

    private function ownedMediaEvent(int $appId, User $user, int $eventId): Event
    {
        $event = $this->ownedEvent($appId, $user, $eventId);
        abort_unless(filled($event->image), 404, 'Mídia não encontrada neste contexto.');

        return $event;
    }

    private function ownedEvent(int $appId, User $user, int $eventId): Event
    {
        $event = Event::query()
            ->where('app_id', $appId)
            ->with('production:id,app_id,name,slug,user_id,app_slug')
            ->findOrFail($eventId);

        abort_unless(
            $event->production && (int) $event->production->app_id === $appId,
            404,
            'Mídia não encontrada neste contexto.'
        );

        abort_unless(
            $this->canManageAll($user) || (int) $event->production->user_id === (int) $user->id,
            403,
            'Você não pode acessar esta mídia.'
        );

        return $event;
    }

    private function canManageAll(User $user): bool
    {
        return $user->hasProfile('Administrador')
            || strtolower(trim((string) $user->email)) === 'petertecnet@gmail.com';
    }

    private function uploadedSoundtrackItems(Event $event): array
    {
        $additional = is_array($event->additional_info) ? $event->additional_info : [];
        $soundtrack = is_array($additional['soundtrack'] ?? null) ? $additional['soundtrack'] : [];

        return array_values(array_filter(
            (array) ($soundtrack['items'] ?? []),
            static fn ($item) => is_array($item)
                && ($item['type'] ?? null) === 'audio'
                && ($item['source'] ?? null) === 'upload'
                && filled($item['id'] ?? null)
                && filled($item['path'] ?? null),
        ));
    }

    private function serializeEventCover(Event $event): array
    {
        $path = ltrim((string) $event->image, '/');
        $metadata = $this->storageMetadata($path);

        return [
            'id' => 'event-cover-'.$event->id,
            'source' => 'event_cover',
            'type' => 'image',
            'mime_type' => $metadata['mime_type'],
            'size' => $metadata['size'],
            'available' => $metadata['available'],
            'path' => $path,
            'url' => $metadata['url'],
            'download_path' => '/event-media/'.$event->id.'/download',
            'media_title' => $event->title,
            'event_id' => $event->id,
            'event_title' => $event->title,
            'event_slug' => $event->slug,
            'event_start_date' => $event->start_date,
            'production_id' => $event->production_id,
            'production_name' => $event->production?->name,
            'created_at' => $event->created_at,
            'updated_at' => $event->updated_at,
        ];
    }

    private function serializeSoundtrackItem(Event $event, array $item): array
    {
        $path = ltrim((string) ($item['path'] ?? ''), '/');
        $metadata = $this->storageMetadata($path);
        $itemId = (string) $item['id'];
        $title = trim((string) ($item['title'] ?? '')) ?: 'Áudio do evento';

        return [
            'id' => 'event-audio-'.$event->id.'-'.$itemId,
            'source' => 'event_soundtrack',
            'type' => 'audio',
            'mime_type' => $metadata['mime_type'],
            'size' => $metadata['size'],
            'available' => $metadata['available'],
            'path' => $path,
            'url' => $metadata['url'],
            'download_path' => '/event-media/'.$event->id.'/soundtrack/'.rawurlencode($itemId).'/download',
            'media_title' => $title,
            'event_id' => $event->id,
            'event_title' => $event->title,
            'event_slug' => $event->slug,
            'event_start_date' => $event->start_date,
            'production_id' => $event->production_id,
            'production_name' => $event->production?->name,
            'created_at' => $event->created_at,
            'updated_at' => data_get($event->additional_info, 'soundtrack.updated_at', $event->updated_at),
        ];
    }

    private function storageMetadata(string $path): array
    {
        $disk = Storage::disk('public');
        $available = $path !== '' && $disk->exists($path);
        $size = null;
        $mimeType = null;

        if ($available) {
            try {
                $size = $disk->size($path);
                $mimeType = $disk->mimeType($path);
            } catch (Throwable) {
                // Metadata is optional; access and availability are checked separately.
            }
        }

        $publicUrl = $path !== '' ? $disk->url($path) : null;
        if ($publicUrl && ! Str::startsWith($publicUrl, ['http://', 'https://'])) {
            $publicUrl = url($publicUrl);
        }

        return [
            'available' => $available,
            'size' => $size,
            'mime_type' => $mimeType,
            'url' => $publicUrl,
        ];
    }

    private function downloadStoredPath(string $path, string $baseFilename, string $fallbackExtension)
    {
        $disk = Storage::disk('public');
        abort_unless($path !== '' && $disk->exists($path), 404, 'A mídia não está mais disponível no armazenamento.');

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: $fallbackExtension);

        return $disk->download($path, $baseFilename.'.'.$extension);
    }
}
