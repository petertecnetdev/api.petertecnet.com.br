<?php

namespace App\Domain\Creative\Services;

final class CreativeSafeZonePlanner
{
    private const ZONES = [
        'story' => [
            'headline' => ['x' => 0.07, 'y' => 0.27, 'w' => 0.70, 'h' => 0.30],
            'meta' => ['x' => 0.07, 'y' => 0.08, 'w' => 0.86, 'h' => 0.14],
            'cta' => ['x' => 0.07, 'y' => 0.76, 'w' => 0.86, 'h' => 0.16],
        ],
        'post' => [
            'headline' => ['x' => 0.07, 'y' => 0.34, 'w' => 0.72, 'h' => 0.28],
            'meta' => ['x' => 0.07, 'y' => 0.07, 'w' => 0.86, 'h' => 0.15],
            'cta' => ['x' => 0.07, 'y' => 0.78, 'w' => 0.86, 'h' => 0.14],
        ],
        'portrait' => [
            'headline' => ['x' => 0.07, 'y' => 0.34, 'w' => 0.72, 'h' => 0.28],
            'meta' => ['x' => 0.07, 'y' => 0.07, 'w' => 0.86, 'h' => 0.15],
            'cta' => ['x' => 0.07, 'y' => 0.78, 'w' => 0.86, 'h' => 0.14],
        ],
        'cover' => [
            'headline' => ['x' => 0.06, 'y' => 0.42, 'w' => 0.54, 'h' => 0.34],
            'meta' => ['x' => 0.06, 'y' => 0.08, 'w' => 0.50, 'h' => 0.18],
            'cta' => ['x' => 0.64, 'y' => 0.72, 'w' => 0.30, 'h' => 0.16],
        ],
        'landscape' => [
            'headline' => ['x' => 0.06, 'y' => 0.42, 'w' => 0.54, 'h' => 0.34],
            'meta' => ['x' => 0.06, 'y' => 0.08, 'w' => 0.50, 'h' => 0.18],
            'cta' => ['x' => 0.64, 'y' => 0.72, 'w' => 0.30, 'h' => 0.16],
        ],
        'og' => [
            'headline' => ['x' => 0.06, 'y' => 0.34, 'w' => 0.58, 'h' => 0.36],
            'meta' => ['x' => 0.06, 'y' => 0.08, 'w' => 0.54, 'h' => 0.18],
            'cta' => ['x' => 0.68, 'y' => 0.72, 'w' => 0.26, 'h' => 0.16],
        ],
        'square' => [
            'headline' => ['x' => 0.07, 'y' => 0.37, 'w' => 0.72, 'h' => 0.30],
            'meta' => ['x' => 0.07, 'y' => 0.07, 'w' => 0.86, 'h' => 0.16],
            'cta' => ['x' => 0.07, 'y' => 0.78, 'w' => 0.86, 'h' => 0.14],
        ],
    ];

    public function forFormat(string $format): array
    {
        return self::ZONES[$format] ?? self::ZONES['cover'];
    }

    public function prompt(array $zones): string
    {
        $headline = $zones['headline'];
        $meta = $zones['meta'];
        $cta = $zones['cta'];

        return sprintf(
            'Reserve calm negative-space regions for application typography: headline x %.0f%%–%.0f%% / y %.0f%%–%.0f%%; meta x %.0f%%–%.0f%% / y %.0f%%–%.0f%%; CTA x %.0f%%–%.0f%% / y %.0f%%–%.0f%%. Keep faces, hands and focal objects outside these zones.',
            $headline['x'] * 100,
            ($headline['x'] + $headline['w']) * 100,
            $headline['y'] * 100,
            ($headline['y'] + $headline['h']) * 100,
            $meta['x'] * 100,
            ($meta['x'] + $meta['w']) * 100,
            $meta['y'] * 100,
            ($meta['y'] + $meta['h']) * 100,
            $cta['x'] * 100,
            ($cta['x'] + $cta['w']) * 100,
            $cta['y'] * 100,
            ($cta['y'] + $cta['h']) * 100,
        );
    }
}
