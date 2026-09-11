<?php

namespace App\Domain\Events\Services;

use App\Models\Event;
use App\Models\File;
use App\Support\ApplicationContext;
use Illuminate\Support\Carbon;
use RuntimeException;

final class EventSocialPreviewService
{
    private const WIDTH = 1200;
    private const HEIGHT = 630;

    public function __construct(private readonly ApplicationContext $context) {}

    public function findPublicEvent(string $slug): Event
    {
        return Event::query()
            ->where('app_id', $this->context->id())
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($query) => $query
                ->where('is_private', false)
                ->orWhereNull('is_private'))
            ->with('production:id,app_id,name,slug')
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function metadata(Event $event): array
    {
        $application = $this->context->application();
        $appSlug = (string) $application->slug;
        $appName = (string) ($application->name ?: ucfirst($appSlug));
        $siteUrl = rtrim((string) ($application->url ?: config('app.frontend_url', '')), '/');
        $apiUrl = rtrim((string) config('app.url'), '/');
        $version = $this->version($event);
        $encodedSlug = rawurlencode((string) $event->slug);
        $canonical = $siteUrl.'/event/'.$encodedSlug;
        $imageUrl = $apiUrl.'/api/v1/apps/'.rawurlencode($appSlug).'/events/public/'.$encodedSlug.'/share-image.jpg?v='.$version;

        return [
            'pageTitle' => trim((string) $event->title).' | '.$appName,
            'title' => trim((string) $event->title),
            'description' => $this->description($event),
            'canonical' => $canonical,
            'imageUrl' => $imageUrl,
            'imageAlt' => ($event->hasEnded() ? 'Momento do evento ' : 'Banner do evento ').trim((string) $event->title),
            'appName' => $appName,
        ];
    }

    public function previewImagePath(Event $event): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        $sourcePath = $this->sourceImagePath($event);
        if (! $sourcePath || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            return null;
        }

        $cacheDirectory = storage_path('app/public/social/event-previews/'.$this->context->id());
        if (! is_dir($cacheDirectory) && ! @mkdir($cacheDirectory, 0775, true) && ! is_dir($cacheDirectory)) {
            return null;
        }

        $targetPath = $cacheDirectory.'/'.$event->getKey().'-'.$this->version($event).'.jpg';
        if (is_file($targetPath) && filesize($targetPath) > 0) {
            return $targetPath;
        }

        $binary = @file_get_contents($sourcePath);
        if ($binary === false || $binary === '') {
            return null;
        }

        $source = @imagecreatefromstring($binary);
        if (! $source) {
            return null;
        }

        try {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            if ($sourceWidth < 1 || $sourceHeight < 1) {
                return null;
            }

            $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
            if (! $canvas) {
                return null;
            }

            try {
                $this->renderBlurredBackdrop($canvas, $source, $sourceWidth, $sourceHeight);
                $this->renderForegroundFlyer($canvas, $source, $sourceWidth, $sourceHeight);

                $temporaryPath = $targetPath.'.'.bin2hex(random_bytes(6)).'.tmp';
                if (! imagejpeg($canvas, $temporaryPath, 88)) {
                    @unlink($temporaryPath);
                    return null;
                }

                @chmod($temporaryPath, 0664);
                if (! @rename($temporaryPath, $targetPath)) {
                    @unlink($temporaryPath);
                    return null;
                }

                return $targetPath;
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            imagedestroy($source);
        }
    }

    public function sourceImageUrl(Event $event): string
    {
        $revive = $this->reviveImage($event);
        if ($revive) {
            return (string) ($revive->public_url ?: '');
        }

        $image = trim((string) $event->image);
        if ($image === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $image)) {
            return $image;
        }

        $relative = ltrim(preg_replace('#^storage/#', '', $image), '/');
        return rtrim((string) config('app.url'), '/').'/storage/'.$relative;
    }

    private function sourceImagePath(Event $event): ?string
    {
        $revive = $this->reviveImage($event);
        if ($revive && $revive->storage !== 'external' && $revive->path) {
            $path = storage_path('app/public/'.ltrim((string) $revive->path, '/'));
            if (is_file($path)) {
                return $path;
            }
        }

        $image = trim((string) $event->image);
        if ($image === '' || preg_match('#^https?://#i', $image)) {
            return null;
        }

        $relative = ltrim(preg_replace('#^storage/#', '', $image), '/');
        $path = storage_path('app/public/'.$relative);

        return is_file($path) ? $path : null;
    }

    private function reviveImage(Event $event): ?File
    {
        if (! $event->hasEnded()) {
            return null;
        }

        return File::query()
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'Event')
            ->where('entity_id', $event->id)
            ->where('group', 'event_revive')
            ->where('status', 'active')
            ->where('visibility', 'public')
            ->orderByDesc('is_primary')
            ->orderBy('position')
            ->orderByDesc('created_at')
            ->first();
    }

    private function description(Event $event): string
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $parts = [];

        if ($event->start_date) {
            $parts[] = Carbon::parse($event->start_date)->timezone($timezone)->format('d/m/Y \à\s H:i');
        }

        $place = trim(implode(' · ', array_filter([
            trim((string) $event->venue),
            trim(implode(' - ', array_filter([
                trim((string) $event->city),
                trim((string) ($event->uf ?: $event->state)),
            ]))),
        ])));
        if ($place !== '') {
            $parts[] = $place;
        }

        $parts[] = $event->hasEnded()
            ? 'Reviva os momentos, avaliações e histórias deste evento na '.($this->context->application()->name ?: 'plataforma')
            : 'Confira informações e ingressos na '.($this->context->application()->name ?: 'plataforma');

        return implode(' · ', $parts);
    }

    private function version(Event $event): int
    {
        $timestamp = $event->updated_at?->getTimestamp();

        if ($event->hasEnded()) {
            $mediaTimestamp = $this->reviveImage($event)?->updated_at?->getTimestamp();
            $timestamp = max((int) ($timestamp ?: 0), (int) ($mediaTimestamp ?: 0));
        }

        return max(1, (int) ($timestamp ?: $event->getKey()));
    }

    /** @param resource|\GdImage $canvas @param resource|\GdImage $source */
    private function renderBlurredBackdrop($canvas, $source, int $sourceWidth, int $sourceHeight): void
    {
        $scale = max(self::WIDTH / $sourceWidth, self::HEIGHT / $sourceHeight);
        $scaledWidth = max(1, (int) ceil($sourceWidth * $scale));
        $scaledHeight = max(1, (int) ceil($sourceHeight * $scale));
        $backdrop = imagecreatetruecolor($scaledWidth, $scaledHeight);
        if (! $backdrop) {
            throw new RuntimeException('Unable to allocate event social preview backdrop.');
        }

        try {
            imagecopyresampled($backdrop, $source, 0, 0, 0, 0, $scaledWidth, $scaledHeight, $sourceWidth, $sourceHeight);
            for ($i = 0; $i < 6; $i++) {
                imagefilter($backdrop, IMG_FILTER_GAUSSIAN_BLUR);
            }

            $sourceX = max(0, (int) floor(($scaledWidth - self::WIDTH) / 2));
            $sourceY = max(0, (int) floor(($scaledHeight - self::HEIGHT) / 2));
            imagecopy($canvas, $backdrop, 0, 0, $sourceX, $sourceY, self::WIDTH, self::HEIGHT);

            $overlay = imagecolorallocatealpha($canvas, 0, 0, 0, 58);
            imagefilledrectangle($canvas, 0, 0, self::WIDTH, self::HEIGHT, $overlay);
        } finally {
            imagedestroy($backdrop);
        }
    }

    /** @param resource|\GdImage $canvas @param resource|\GdImage $source */
    private function renderForegroundFlyer($canvas, $source, int $sourceWidth, int $sourceHeight): void
    {
        $maxWidth = self::WIDTH - 48;
        $maxHeight = self::HEIGHT - 32;
        $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        $targetWidth = max(1, (int) floor($sourceWidth * $scale));
        $targetHeight = max(1, (int) floor($sourceHeight * $scale));
        $targetX = (int) floor((self::WIDTH - $targetWidth) / 2);
        $targetY = (int) floor((self::HEIGHT - $targetHeight) / 2);

        $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 70);
        imagefilledrectangle($canvas, $targetX + 8, $targetY + 8, $targetX + $targetWidth + 8, $targetY + $targetHeight + 8, $shadow);
        imagecopyresampled($canvas, $source, $targetX, $targetY, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    }
}
