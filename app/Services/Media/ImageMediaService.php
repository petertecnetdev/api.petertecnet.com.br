<?php

namespace App\Services\Media;

use App\Jobs\GenerateImageVariants;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class ImageMediaService
{
    public const VARIANTS = [
        'thumbnail' => [480, 853, 80],
        'card' => [960, 1707, 84],
        'feed' => [1080, 1920, 85],
        'hero' => [1440, 2560, 86],
    ];

    private const PIPELINE_FILE_PATTERN = '/\/(?:original|thumbnail|card|feed|hero|background|og)\.webp$/i';

    public function store(UploadedFile $file, string $collection = 'media'): array
    {
        $collection = trim($collection, '/');
        $directory = 'images/' . ($collection ?: 'media') . '/' . Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?: 'bin';
        $sourcePath = $directory . '/source.' . $extension;
        $originalPath = $directory . '/original.webp';
        $heroPath = $directory . '/hero.webp';

        Storage::disk('public')->putFileAs($directory, $file, basename($sourcePath));

        $image = Image::make($file->getRealPath())->orientate();
        $width = $image->width();
        $height = $image->height();

        $absoluteOriginal = Storage::disk('public')->path($originalPath);
        $this->ensureDirectory(dirname($absoluteOriginal));
        $image->encode('webp', 92)->save($absoluteOriginal);

        $hero = clone $image;
        [$heroWidth, $heroHeight, $heroQuality] = self::VARIANTS['hero'];
        $hero->resize($heroWidth, $heroHeight, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        $hero->encode('webp', $heroQuality)->save(Storage::disk('public')->path($heroPath));
        $hero->destroy();
        $image->destroy();

        GenerateImageVariants::dispatch($sourcePath);

        return [
            'path' => $heroPath,
            'source_path' => $sourcePath,
            'width' => $width,
            'height' => $height,
            'aspect_ratio' => $height > 0 ? round($width / $height, 5) : null,
            'orientation' => $this->orientation($width, $height),
            'quality' => $this->quality($width),
            'variants' => $this->variantPaths($heroPath),
        ];
    }

    public function variantPaths(?string $path): array
    {
        if (! $path) {
            return [];
        }

        $normalized = ltrim($path, '/');
        if (! preg_match(self::PIPELINE_FILE_PATTERN, $normalized)) {
            return [
                'original' => $normalized,
                'thumbnail' => $normalized,
                'card' => $normalized,
                'feed' => $normalized,
                'hero' => $normalized,
                'background' => $normalized,
                'og' => $normalized,
            ];
        }

        $directory = dirname($normalized);

        return [
            'original' => $directory . '/original.webp',
            'thumbnail' => $directory . '/thumbnail.webp',
            'card' => $directory . '/card.webp',
            'feed' => $directory . '/feed.webp',
            'hero' => $directory . '/hero.webp',
            'background' => $directory . '/background.webp',
            'og' => $directory . '/og.webp',
        ];
    }

    public function delete(?string $path): void
    {
        if (! $path) {
            return;
        }

        $normalized = ltrim($path, '/');
        if (! str_starts_with($normalized, 'images/')) {
            return;
        }

        if (preg_match(self::PIPELINE_FILE_PATTERN, $normalized)) {
            Storage::disk('public')->deleteDirectory(dirname($normalized));
            return;
        }

        Storage::disk('public')->delete($normalized);
    }

    public function quality(int $width): array
    {
        if ($width >= 1080) {
            return ['level' => 'excellent', 'label' => 'Excelente'];
        }
        if ($width >= 900) {
            return ['level' => 'good', 'label' => 'Boa'];
        }
        if ($width >= 720) {
            return ['level' => 'acceptable', 'label' => 'Aceitável'];
        }
        if ($width >= 480) {
            return ['level' => 'low', 'label' => 'Baixa'];
        }

        return ['level' => 'very_low', 'label' => 'Muito baixa'];
    }

    private function orientation(int $width, int $height): string
    {
        if ($width === $height) {
            return 'square';
        }

        return $width > $height ? 'landscape' : 'portrait';
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
