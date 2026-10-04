<?php

namespace App\Domain\Media\Library\Services;

use App\Domain\Media\Library\Models\MediaAsset;
use App\Domain\Media\Library\Models\MediaVariant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Throwable;

final class MediaVariantService
{
    public function generate(MediaAsset $asset): void
    {
        if ($asset->kind !== 'image' || ! $asset->storage_disk || ! $asset->storage_path) {
            return;
        }

        $disk = Storage::disk($asset->storage_disk);
        if (! $disk->exists($asset->storage_path)) {
            return;
        }

        $binary = $disk->get($asset->storage_path);
        $quality = max(60, min(95, (int) config('media_library.variant_quality', 86)));
        $background = (string) config('media_library.variant_background', '#101010');

        foreach ((array) config('media_library.variants', []) as $name => $spec) {
            $width = max(1, (int) ($spec['width'] ?? 0));
            $height = max(1, (int) ($spec['height'] ?? 0));
            if ($width < 1 || $height < 1) {
                continue;
            }

            $source = Image::make($binary)->orientate();
            $source->resize($width, $height, function ($constraint): void {
                $constraint->aspectRatio();
                $constraint->upsize();
            });

            $canvas = Image::canvas($width, $height, $background);
            $canvas->insert($source, 'center');

            $path = sprintf(
                'applications/%d/library/variants/%s/%s.webp',
                $asset->application_id,
                $asset->uuid,
                Str::slug((string) $name) ?: 'variant'
            );
            $encoded = (string) $canvas->encode('webp', $quality);
            $disk->put($path, $encoded);

            MediaVariant::query()->updateOrCreate(
                ['media_asset_id' => $asset->id, 'name' => (string) $name],
                [
                    'mime_type' => 'image/webp',
                    'file_size' => strlen($encoded),
                    'width' => $width,
                    'height' => $height,
                    'storage_disk' => $asset->storage_disk,
                    'storage_path' => $path,
                    'public_url' => $this->storageUrl($asset->storage_disk, $path),
                    'metadata' => [
                        'fit' => 'contain',
                        'background' => $background,
                        'source_preserved' => true,
                    ],
                ]
            );
        }
    }

    private function storageUrl(string $diskName, string $path): ?string
    {
        try {
            $url = Storage::disk($diskName)->url($path);
            if (! $url) {
                return null;
            }

            return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
        } catch (Throwable) {
            return null;
        }
    }
}
