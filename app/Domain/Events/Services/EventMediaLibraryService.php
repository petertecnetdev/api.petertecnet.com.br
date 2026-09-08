<?php

namespace App\Domain\Events\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class EventMediaLibraryService
{
    public function paginate(int $appId, User $user, array $filters): LengthAwarePaginator
    {
        $query = Event::query()
            ->where('app_id', $appId)
            ->whereNotNull('image')
            ->where('image', '<>', '')
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

        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function ($eventQuery) use ($term): void {
                $eventQuery
                    ->where('title', 'like', "%{$term}%")
                    ->orWhereHas('production', fn ($production) => $production->where('name', 'like', "%{$term}%"));
            });
        }

        $media = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 24))
            ->appends(array_filter([
                'q' => $filters['q'] ?? null,
                'production_id' => $filters['production_id'] ?? null,
                'per_page' => $filters['per_page'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''));

        $media->through(fn (Event $event) => $this->serialize($event));

        return $media;
    }

    public function find(int $appId, User $user, int $eventId): array
    {
        return $this->serialize($this->ownedMediaEvent($appId, $user, $eventId));
    }

    public function download(int $appId, User $user, int $eventId)
    {
        $event = $this->ownedMediaEvent($appId, $user, $eventId);
        $path = ltrim((string) $event->image, '/');
        $disk = Storage::disk('public');

        abort_unless($path !== '' && $disk->exists($path), 404, 'A mídia não está mais disponível no armazenamento.');

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'webp');
        $filename = (Str::slug((string) $event->title) ?: 'evento') . '-capa.' . $extension;

        return $disk->download($path, $filename);
    }

    private function ownedMediaEvent(int $appId, User $user, int $eventId): Event
    {
        $event = Event::query()
            ->where('app_id', $appId)
            ->whereNotNull('image')
            ->where('image', '<>', '')
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

    private function serialize(Event $event): array
    {
        $path = ltrim((string) $event->image, '/');
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
            'id' => $event->id,
            'source' => 'event_cover',
            'type' => 'image',
            'mime_type' => $mimeType,
            'size' => $size,
            'available' => $available,
            'path' => $path,
            'url' => $publicUrl,
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
}
