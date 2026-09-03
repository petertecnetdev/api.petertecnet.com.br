<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManagerStatic as Image;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class OptimizedMediaController extends Controller
{
    public function capabilities(): Response
    {
        return response()->json([
            'success' => true,
            'data' => [
                'avif' => $this->supportsAvif(),
                'webp' => $this->supportsWebp(),
                'widths' => [320, 480, 640, 960, 1280, 1600],
            ],
        ])->setPublic()->setMaxAge(86400);
    }

    public function show(Request $request, string $uuid): BinaryFileResponse|Response
    {
        $data = $request->validate([
            'width' => ['nullable', 'integer', 'min:120', 'max:2400'],
            'format' => ['nullable', 'string', 'in:auto,avif,webp,jpeg,png'],
            'quality' => ['nullable', 'integer', 'min:45', 'max:92'],
        ]);

        $file = File::query()->where('uuid', $uuid)->visible()->firstOrFail();
        abort_unless($file->type === 'image' || str_starts_with((string) $file->mime_type, 'image/'), 404);

        if (! in_array($file->storage ?: 'public', ['public', 'local'], true)) {
            return redirect()->away($file->public_url, 302);
        }

        $disk = Storage::disk($file->storage ?: 'public');
        abort_unless($file->path && $disk->exists($file->path), 404);
        $source = $disk->path($file->path);
        $sourceMime = strtolower((string) $file->mime_type);
        if (str_contains($sourceMime, 'svg')) {
            return response()->file($source, ['Cache-Control' => 'public, max-age=604800, immutable']);
        }

        $width = (int) ($data['width'] ?? min(1280, max(320, (int) ($file->width ?: 1280))));
        $quality = (int) ($data['quality'] ?? 78);
        $format = $this->resolveFormat($request, $data['format'] ?? 'auto', $sourceMime);
        $extension = $format === 'jpeg' ? 'jpg' : $format;
        $cachePath = "optimized/{$file->uuid}/{$width}-q{$quality}.{$extension}";
        $cacheDisk = Storage::disk('public');

        if (! $cacheDisk->exists($cachePath)) {
            try {
                $image = Image::make($source);
                $image->resize($width, null, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
                $encoded = $image->encode($format, $quality);
                $cacheDisk->put($cachePath, (string) $encoded);
            } catch (\Throwable $exception) {
                if ($format === 'avif' && $this->supportsWebp()) {
                    $format = 'webp';
                    $extension = 'webp';
                    $cachePath = "optimized/{$file->uuid}/{$width}-q{$quality}.webp";
                    if (! $cacheDisk->exists($cachePath)) {
                        $image = Image::make($source);
                        $image->resize($width, null, function ($constraint) {
                            $constraint->aspectRatio();
                            $constraint->upsize();
                        });
                        $cacheDisk->put($cachePath, (string) $image->encode('webp', $quality));
                    }
                } else {
                    return response()->file($source, ['Cache-Control' => 'public, max-age=604800, immutable']);
                }
            }
        }

        $response = response()->file($cacheDisk->path($cachePath), [
            'Content-Type' => $this->mime($format),
            'Cache-Control' => 'public, max-age=2592000, immutable',
            'Vary' => 'Accept',
        ]);
        $response->setEtag(sha1($file->uuid . '|' . $width . '|' . $quality . '|' . $format . '|' . $file->updated_at));
        return $response;
    }

    private function resolveFormat(Request $request, string $requested, string $sourceMime): string
    {
        if ($requested !== 'auto') {
            if ($requested === 'avif' && ! $this->supportsAvif()) return $this->supportsWebp() ? 'webp' : 'jpeg';
            if ($requested === 'webp' && ! $this->supportsWebp()) return 'jpeg';
            return $requested;
        }

        $accept = strtolower((string) $request->header('Accept'));
        if (str_contains($accept, 'image/avif') && $this->supportsAvif()) return 'avif';
        if (str_contains($accept, 'image/webp') && $this->supportsWebp()) return 'webp';
        return str_contains($sourceMime, 'png') ? 'png' : 'jpeg';
    }

    private function supportsWebp(): bool
    {
        if (function_exists('imagewebp')) return true;
        return class_exists(\Imagick::class) && in_array('WEBP', \Imagick::queryFormats('WEBP'), true);
    }

    private function supportsAvif(): bool
    {
        if (function_exists('imageavif')) return true;
        return class_exists(\Imagick::class) && in_array('AVIF', \Imagick::queryFormats('AVIF'), true);
    }

    private function mime(string $format): string
    {
        return match ($format) {
            'avif' => 'image/avif',
            'webp' => 'image/webp',
            'png' => 'image/png',
            default => 'image/jpeg',
        };
    }
}
