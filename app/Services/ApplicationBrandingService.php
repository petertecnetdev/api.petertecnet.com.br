<?php

namespace App\Services;

use App\Models\Application;
use Illuminate\Support\Str;

class ApplicationBrandingService
{
    public const ASSET_FIELDS = [
        'logo',
        'logo_light',
        'logo_dark',
        'icon',
        'favicon',
        'social_image',
    ];

    public const COLOR_FIELDS = [
        'primary_color',
        'secondary_color',
        'accent_color',
    ];

    public function published(Application $application): array
    {
        return $this->normalize($application, $application->branding ?: []);
    }

    public function draft(Application $application): array
    {
        return $this->normalize(
            $application,
            $application->branding_draft ?? $application->branding ?? []
        );
    }

    public function normalize(Application $application, array $branding): array
    {
        $legacyLogo = $application->logo ?: ($application->url ? rtrim($application->url, '/') . '/logo' : null);
        $logo = $branding['logo'] ?? $legacyLogo;
        $icon = $branding['icon'] ?? $logo;

        return [
            'display_name' => $branding['display_name'] ?? $application->name,
            'short_name' => $branding['short_name'] ?? $application->name,
            'seo_description' => $branding['seo_description'] ?? $application->description,
            'logo' => $logo,
            'logo_light' => $branding['logo_light'] ?? $logo,
            'logo_dark' => $branding['logo_dark'] ?? $logo,
            'icon' => $icon,
            'favicon' => $branding['favicon'] ?? $icon,
            'social_image' => $branding['social_image'] ?? $logo,
            'primary_color' => $branding['primary_color'] ?? null,
            'secondary_color' => $branding['secondary_color'] ?? null,
            'accent_color' => $branding['accent_color'] ?? null,
        ];
    }

    public function editable(array $branding): array
    {
        return array_intersect_key($branding, array_flip([
            'display_name',
            'short_name',
            'seo_description',
            ...self::ASSET_FIELDS,
            ...self::COLOR_FIELDS,
        ]));
    }

    public function assetFilename(Application $application, string $asset, string $extension): string
    {
        $slug = Str::slug((string) ($application->slug ?: $application->name)) ?: 'application';
        $suffix = match ($asset) {
            'logo' => 'logo',
            'logo_light' => 'logo-light',
            'logo_dark' => 'logo-dark',
            'icon' => 'icon',
            'favicon' => 'favicon',
            'social_image' => 'social-image',
            default => Str::slug($asset) ?: 'asset',
        };
        $safeExtension = strtolower(ltrim($extension, '.')) ?: 'png';

        return "{$slug}-{$suffix}.{$safeExtension}";
    }

    public function publicPayload(Application $application): array
    {
        return [
            ...$this->published($application),
            'version' => (int) $application->branding_version,
            'published_at' => $application->branding_published_at?->toIso8601String(),
        ];
    }
}
