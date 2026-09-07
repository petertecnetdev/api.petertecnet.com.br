<?php

namespace App\Domain\Events\Services;

use App\Models\Event;
use App\Support\ApplicationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

final class EventSocialPreviewService
{
    private const PREVIEW_WIDTH = 1200;
    private const PREVIEW_HEIGHT = 630;
    private const PREVIEW_VERSION = 'v1';

    public function __construct(private readonly ApplicationContext $context) {}

    public function findPublicEvent(string $slug): Event
    {
        return Event::query()
            ->where('app_id', $this->context->id())
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($query) => $query->where('is_private', false)->orWhereNull('is_private'))
            ->with('production:id,app_id,name,slug')
            ->firstOrFail();
    }

    /** @return array<string, string> */
    public function metadata(Event $event): array
    {
        $application = $this->context->application();
        $siteUrl = rtrim((string) $application->url, '/');
        $canonicalUrl = $siteUrl.'/event/'.rawurlencode((string) $event->slug);
        $appName = trim((string) $application->name) ?: 'Peter Tecnet';
        $eventTitle = trim((string) $event->title) ?: 'Evento';
        $title = $eventTitle.' | '.$appName;
        $description = $this->description($event, $appName);
        $version = $event->updated_at?->getTimestamp() ?: (int) $event->id;
        $imageUrl = url('/api/v1/apps/'.rawurlencode($this->context->slug()).'/events/public/'.rawurlencode((string) $event->slug).'/share-image.jpg').'?v='.$version;

        return [
            'title' => $title,
            'eventTitle' => $eventTitle,
            'description' => $description,
            'canonicalUrl' => $canonicalUrl,
            'imageUrl' => $imageUrl,
            'imageAlt' => 'Banner do evento '.$eventTitle,
            'siteName' => $appName,
        ];
    }

    public function sourceImageUrl(Event $event): string
    {
        $value = trim((string) $event->image);
        if ($value !== '') {
            if (preg_match('#^https?://#i', $value)) {
                return $value;
            }

            return rtrim((string) config('app.url'), '/').'/storage/'.ltrim($value, '/');
        }

        $application = $this->context->application();
        $logo = trim((string) $application->logo);
        if ($logo !== '') {
            if (preg_match('#^https?://#i', $logo)) {
                return $logo;
            }

            return rtrim((string) $application->url, '/').'/'.ltrim($logo, '/');
        }

        return rtrim((string) $application->url, '/').'/images/logo.png';
    }

    public function previewImagePath(Event $event): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        $sourcePath = $this->localSourcePath($event);
        if (! $sourcePath) {
            return null;
        }

        $fingerprint = substr(hash('sha256', implode('|', [
            self::PREVIEW_VERSION,
            (string) $event->id,
            (string) $event->image,
            (string) optional($event->updated_at)->timestamp,
        ])), 0, 20);
        $relativeTarget = 'social-previews/events/'.$this->context->id().'/'.$event->id.'-'.$fingerprint.'.jpg';
        $disk = Storage::disk('public');
        $targetPath = $disk->path($relativeTarget);

        if (is_file($targetPath) && filesize($targetPath) > 0) {
            return $targetPath;
        }

        $bytes = @file_get_contents($sourcePath);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            return null;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($source);
            return null;
        }

        $canvas = imagecreatetruecolor(self::PREVIEW_WIDTH, self::PREVIEW_HEIGHT);
        if (! $canvas) {
            imagedestroy($source);
            return null;
        }

        imagealphablending($canvas, true);
        $this->paintBackground($canvas, $source, $sourceWidth, $sourceHeight);
        $this->paintPoster($canvas, $source, $sourceWidth, $sourceHeight);

        $disk->makeDirectory(dirname($relativeTarget));
        $written = @imagejpeg($canvas, $targetPath, 88);

        imagedestroy($canvas);
        imagedestroy($source);

        return $written ? $targetPath : null;
    }

    private function description(Event $event, string $appName): string
    {
        $parts = [];
        if ($event->start_date) {
            $parts[] = Carbon::parse($event->start_date)
                ->timezone(config('app.timezone', 'America/Sao_Paulo'))
                ->format('d/m/Y \à\s H:i');
        }

        if ($event->venue) {
            $parts[] = trim((string) $event->venue);
        }

        $city = trim((string) $event->city);
        $uf = strtoupper(trim((string) ($event->uf ?: $event->state)));
        if ($city !== '') {
            $parts[] = $city.($uf !== '' ? ' - '.$uf : '');
        }

        $parts[] = 'Confira informações e ingressos na '.$appName;

        return implode(' · ', array_filter($parts));
    }

    private function localSourcePath(Event $event): ?string
    {
        $value = trim((string) $event->image);
        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value)) {
            $path = (string) parse_url($value, PHP_URL_PATH);
            if (! str_starts_with($path, '/storage/')) {
                return null;
            }
            $value = substr($path, strlen('/storage/'));
        }

        $relative = ltrim($value, '/');
        if (str_starts_with($relative, 'storage/')) {
            $relative = substr($relative, strlen('storage/'));
        }

        $disk = Storage::disk('public');
        $root = realpath($disk->path(''));
        $candidate = realpath($disk->path($relative));
        if (! $root || ! $candidate || ! str_starts_with($candidate, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($candidate) ? $candidate : null;
    }

    private function paintBackground($canvas, $source, int $sourceWidth, int $sourceHeight): void
    {
        $smallWidth = 300;
        $smallHeight = 158;
        $background = imagecreatetruecolor($smallWidth, $smallHeight);
        $coverScale = max($smallWidth / $sourceWidth, $smallHeight / $sourceHeight);
        $scaledWidth = (int) ceil($sourceWidth * $coverScale);
        $scaledHeight = (int) ceil($sourceHeight * $coverScale);
        $offsetX = (int) floor(($smallWidth - $scaledWidth) / 2);
        $offsetY = (int) floor(($smallHeight - $scaledHeight) / 2);

        imagecopyresampled($background, $source, $offsetX, $offsetY, 0, 0, $scaledWidth, $scaledHeight, $sourceWidth, $sourceHeight);
        if (function_exists('imagefilter')) {
            for ($i = 0; $i < 4; $i++) {
                imagefilter($background, IMG_FILTER_GAUSSIAN_BLUR);
            }
        }

        imagecopyresampled($canvas, $background, 0, 0, 0, 0, self::PREVIEW_WIDTH, self::PREVIEW_HEIGHT, $smallWidth, $smallHeight);
        imagedestroy($background);

        $overlay = imagecolorallocatealpha($canvas, 0, 0, 0, 54);
        imagefilledrectangle($canvas, 0, 0, self::PREVIEW_WIDTH - 1, self::PREVIEW_HEIGHT - 1, $overlay);
    }

    private function paintPoster($canvas, $source, int $sourceWidth, int $sourceHeight): void
    {
        $maxWidth = self::PREVIEW_WIDTH - 100;
        $maxHeight = self::PREVIEW_HEIGHT - 60;
        $fitScale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        $targetWidth = max(1, (int) round($sourceWidth * $fitScale));
        $targetHeight = max(1, (int) round($sourceHeight * $fitScale));
        $x = (int) floor((self::PREVIEW_WIDTH - $targetWidth) / 2);
        $y = (int) floor((self::PREVIEW_HEIGHT - $targetHeight) / 2);

        $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 72);
        imagefilledrectangle($canvas, $x - 12, $y - 12, $x + $targetWidth + 12, $y + $targetHeight + 12, $shadow);
        imagecopyresampled($canvas, $source, $x, $y, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    }
}
