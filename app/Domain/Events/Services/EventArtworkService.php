<?php

namespace App\Domain\Events\Services;

use App\Domain\Creative\Services\CloudflareImageGenerator;
use App\Domain\Creative\Services\CreativePromptTemplateService;
use App\Jobs\GenerateEventArtwork;
use App\Models\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use RuntimeException;
use Throwable;

final class EventArtworkService
{
    private const WIDTH = 1024;
    private const HEIGHT = 1536;
    private const REQUEST_COOLDOWN_HOURS = 6;

    public function __construct(
        private readonly CloudflareImageGenerator $generator,
        private readonly CreativePromptTemplateService $templates,
    ) {}

    public function requestGeneration(Event $event): bool
    {
        if (! $event->exists || ! $event->id || ! $event->app_id || $this->hasImage($event)) {
            return false;
        }

        $cacheKey = $this->requestCacheKey((int) $event->app_id, (int) $event->id);
        if (! Cache::add($cacheKey, true, now()->addHours(self::REQUEST_COOLDOWN_HOURS))) {
            return false;
        }

        try {
            GenerateEventArtwork::dispatch((int) $event->id, (int) $event->app_id)->afterResponse();

            return true;
        } catch (Throwable $exception) {
            Cache::forget($cacheKey);
            report($exception);

            return false;
        }
    }

    public function generate(int $eventId, int $applicationId): ?string
    {
        $event = Event::query()
            ->where('app_id', $applicationId)
            ->with('production:id,app_id,name,user_id,app_slug')
            ->find($eventId);

        if (! $event || $this->hasImage($event) || ! $event->production?->user_id) {
            Cache::forget($this->requestCacheKey($applicationId, $eventId));

            return null;
        }

        $input = [
            'subject' => (string) $event->title,
            'description' => (string) ($event->description ?? ''),
            'category' => (string) ($event->category ?? ''),
            'production_name' => (string) ($event->production->name ?? ''),
            'venue' => (string) ($event->venue ?? ''),
            'city' => (string) ($event->city ?? ''),
            'uf' => (string) ($event->uf ?? ''),
            'style' => 'automatic',
            'intensity' => 'balanced',
            'format' => 'cover',
        ];

        $direction = $this->templates->eventDirection($input);
        $prompt = $this->templates->renderEventFlyer($input);
        $result = $this->generator->generate(
            $prompt,
            (int) $event->production->user_id,
            $applicationId,
            [
                'model' => (string) config('creative.cloudflare.event_preview_model'),
                'width' => (int) ($direction['width'] ?? self::WIDTH),
                'height' => (int) ($direction['height'] ?? self::HEIGHT),
                'steps' => (int) config('creative.cloudflare.event_preview_steps', 4),
            ],
        );

        $bytes = $this->decodeGeneratedImage((string) ($result['image'] ?? ''));
        $poster = (string) Image::make($bytes)
            ->orientate()
            ->fit(self::WIDTH, self::HEIGHT)
            ->encode('webp', 88);

        if ($poster === '') {
            throw new RuntimeException('A IA não produziu uma imagem de evento utilizável.');
        }

        $appSlug = Str::slug((string) ($event->app_slug ?: $event->production->app_slug ?: 'app-'.$applicationId));
        $path = 'images/apps/'.$appSlug.'/events/'.Str::uuid().'.webp';

        if (! Storage::disk('public')->put($path, $poster)) {
            throw new RuntimeException('Não foi possível persistir a imagem gerada para o evento.');
        }

        $updated = Event::query()
            ->whereKey($eventId)
            ->where('app_id', $applicationId)
            ->where(function ($query) {
                $query->whereNull('image')->orWhere('image', '');
            })
            ->update([
                'image' => $path,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            Storage::disk('public')->delete($path);
            Cache::forget($this->requestCacheKey($applicationId, $eventId));

            return null;
        }

        Cache::forget($this->requestCacheKey($applicationId, $eventId));

        return $path;
    }

    private function hasImage(Event $event): bool
    {
        return trim((string) ($event->image ?? '')) !== '';
    }

    private function decodeGeneratedImage(string $value): string
    {
        $encoded = trim($value);
        if (preg_match('/^data:image\/(?:jpeg|jpg|png|webp);base64,(.+)$/s', $encoded, $matches)) {
            $encoded = $matches[1];
        }

        $bytes = base64_decode($encoded, true);
        if ($bytes === false || strlen($bytes) < 256) {
            throw new RuntimeException('A IA respondeu sem uma imagem de evento válida.');
        }

        return $bytes;
    }

    private function requestCacheKey(int $applicationId, int $eventId): string
    {
        return sprintf('events:auto-artwork:%d:%d', $applicationId, $eventId);
    }
}
