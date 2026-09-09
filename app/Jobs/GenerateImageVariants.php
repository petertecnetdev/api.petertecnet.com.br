<?php

namespace App\Jobs;

use App\Services\Media\ImageMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class GenerateImageVariants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public string $sourcePath)
    {
    }

    public function handle(): void
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($this->sourcePath)) {
            return;
        }

        $directory = dirname($this->sourcePath);
        $source = Image::make($disk->path($this->sourcePath))->orientate();

        foreach (ImageMediaService::VARIANTS as $name => [$maxWidth, $maxHeight, $quality]) {
            $variant = clone $source;
            $variant->resize($maxWidth, $maxHeight, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
            $variant->encode('webp', $quality)->save($disk->path($directory . '/' . $name . '.webp'));
            $variant->destroy();
        }

        $background = clone $source;
        $background->fit(1600, 900, function ($constraint) {
            $constraint->upsize();
        })->blur(24);
        $background->encode('webp', 72)->save($disk->path($directory . '/background.webp'));
        $background->destroy();

        $og = clone $source;
        $og->fit(1200, 630, function ($constraint) {
            $constraint->upsize();
        })->blur(28);

        $foreground = clone $source;
        $foreground->resize(1200, 630, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        $og->insert($foreground, 'center');
        $og->encode('webp', 84)->save($disk->path($directory . '/og.webp'));

        $foreground->destroy();
        $og->destroy();
        $source->destroy();
    }
}
