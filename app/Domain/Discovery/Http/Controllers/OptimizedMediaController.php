<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Domain\Media\Services\ImageFormatCapabilities;
use App\Http\Controllers\Controller;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManagerStatic as Image;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class OptimizedMediaController extends Controller
{
    public function __construct(private readonly ImageFormatCapabilities $formats) {}

    public function capabilities(): Response
    {
        return response()->json([
            'success' => true,
            'data' => $this->formats->all(),
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
        $format = $this->formats->resolve(
            (string) ($data['format'] ?? 'auto'),
            (string) $request->header('Accept'),
            $sourceMime,
        );
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
                if ($format === 'avif' && $this->formats->supportsWebp()) {
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
            'Content-Type' => $this->formats->mime($format),
            'Cache-Control' => 'public, max-age=2592000, immutable',
            'Vary' => 'Accept',
        ]);
        $response->setEtag(sha1($file->uuid . '|' . $width . '|' . $quality . '|' . $format . '|' . $file->updated_at));
        return $response;
    }
}
