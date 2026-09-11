<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class MediaVariantService
{
    /**
     * Generate lightweight public variants for a locally stored image.
     * The original file is kept untouched as the archival source.
     */
    public function generateImageVariants(File $file): File
    {
        if (
            $file->type !== 'image'
            || $file->storage !== 'public'
            || ! $file->path
            || ! function_exists('imagecreatefromstring')
        ) {
            return $file;
        }

        try {
            $disk = Storage::disk('public');
            $sourcePath = $disk->path($file->path);
            if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
                return $file;
            }

            $binary = file_get_contents($sourcePath);
            if ($binary === false || $binary === '') {
                return $file;
            }

            $source = @imagecreatefromstring($binary);
            if (! $source) {
                return $file;
            }

            try {
                $sourceWidth = imagesx($source);
                $sourceHeight = imagesy($source);
                if ($sourceWidth < 1 || $sourceHeight < 1) {
                    return $file;
                }

                $variants = is_array($file->variants) ? $file->variants : [];
                foreach ([
                    'thumbnail' => 480,
                    'display' => 1440,
                ] as $name => $maxWidth) {
                    $variant = $this->createVariant(
                        $source,
                        $sourceWidth,
                        $sourceHeight,
                        $disk,
                        (string) $file->path,
                        (string) $file->uuid,
                        $name,
                        $maxWidth
                    );

                    if ($variant) {
                        $variants[$name] = $variant;
                    }
                }

                $file->forceFill([
                    'width' => $sourceWidth,
                    'height' => $sourceHeight,
                    'processed' => true,
                    'compressed' => ! empty($variants),
                    'variants' => $variants,
                ])->save();

                return $file->fresh();
            } finally {
                imagedestroy($source);
            }
        } catch (Throwable $e) {
            report($e);
            return $file;
        }
    }

    private function createVariant(
        $source,
        int $sourceWidth,
        int $sourceHeight,
        $disk,
        string $originalPath,
        string $uuid,
        string $name,
        int $maxWidth
    ): ?array {
        $scale = min(1, $maxWidth / max(1, $sourceWidth));
        $width = max(1, (int) floor($sourceWidth * $scale));
        $height = max(1, (int) floor($sourceHeight * $scale));

        $canvas = imagecreatetruecolor($width, $height);
        if (! $canvas) {
            return null;
        }

        try {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
            imagecopyresampled(
                $canvas,
                $source,
                0,
                0,
                0,
                0,
                $width,
                $height,
                $sourceWidth,
                $sourceHeight
            );

            $directory = trim(dirname($originalPath), '.').'/variants';
            $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
            $relativePath = trim($directory, '/').'/'.$uuid.'-'.$name.'.'.$extension;
            $absolutePath = $disk->path($relativePath);
            $absoluteDirectory = dirname($absolutePath);

            if (! is_dir($absoluteDirectory) && ! @mkdir($absoluteDirectory, 0775, true) && ! is_dir($absoluteDirectory)) {
                return null;
            }

            $written = function_exists('imagewebp')
                ? @imagewebp($canvas, $absolutePath, $name === 'thumbnail' ? 78 : 84)
                : @imagejpeg($canvas, $absolutePath, $name === 'thumbnail' ? 80 : 86);

            if (! $written || ! is_file($absolutePath)) {
                @unlink($absolutePath);
                return null;
            }

            @chmod($absolutePath, 0664);

            return [
                'path' => $relativePath,
                'url' => $disk->url($relativePath),
                'width' => $width,
                'height' => $height,
                'mime_type' => function_exists('imagewebp') ? 'image/webp' : 'image/jpeg',
                'file_size' => filesize($absolutePath) ?: null,
            ];
        } finally {
            imagedestroy($canvas);
        }
    }
}
