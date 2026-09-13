<?php

namespace App\Services;

use App\Models\Application;
use Illuminate\Support\Str;

class ApplicationMailBrandingService
{
    public function __construct(
        private readonly ApplicationBrandingService $applicationBranding
    ) {}

    public function forApplication(
        ?Application $application,
        ?string $fallbackName = null,
        ?string $fallbackUrl = null
    ): array {
        $slug = Str::slug((string) ($application?->slug ?: $fallbackName ?: 'peter-tecnet'));
        $published = $application ? $this->applicationBranding->published($application) : [];
        $configured = (array) config("platform.applications.{$slug}.email_brand", []);

        $name = trim((string) ($published['display_name'] ?? $application?->name ?? $fallbackName));
        if ($name === '') {
            $name = 'Peter Tecnet';
        }

        $appUrl = $this->safeBaseUrl(
            (string) ($application?->url ?: $fallbackUrl ?: '')
        );

        $rawBranding = is_array($application?->branding) ? $application->branding : [];
        $logoCandidate = $rawBranding['logo_light']
            ?? $rawBranding['logo']
            ?? $configured['logo_path']
            ?? $application?->logo
            ?? null;

        $logoUrl = $this->assetUrl($logoCandidate, $appUrl);
        if (! $logoUrl && $appUrl) {
            $logoUrl = $this->assetUrl('/images/logo.png', $appUrl);
        }

        $primary = $this->color(
            $published['primary_color'] ?? null,
            $configured['primary_color'] ?? '#6d28d9'
        );
        $secondary = $this->color(
            $published['secondary_color'] ?? null,
            $configured['secondary_color'] ?? '#2563eb'
        );
        $accent = $this->color(
            $published['accent_color'] ?? null,
            $configured['accent_color'] ?? '#0891b2'
        );

        $headerBackground = $this->color(
            $configured['header_background_color'] ?? null,
            '#111827'
        );
        $pageBackground = $this->color(
            $configured['page_background_color'] ?? null,
            '#f5f5fb'
        );
        $surface = $this->color(
            $configured['surface_color'] ?? null,
            '#ffffff'
        );
        $text = $this->color(
            $configured['text_color'] ?? null,
            '#18162a'
        );
        $muted = $this->color(
            $configured['muted_color'] ?? null,
            '#6f6b7d'
        );
        $border = $this->color(
            $configured['border_color'] ?? null,
            '#e7e4ef'
        );

        $isFactory = in_array($slug, ['peter-tecnet', 'petertecnet'], true)
            || Str::lower($name) === 'peter tecnet';

        return [
            'slug' => $slug,
            'name' => $name,
            'short_name' => trim((string) ($published['short_name'] ?? $name)) ?: $name,
            'app_url' => $appUrl,
            'logo_url' => $logoUrl,
            'logo_alt' => 'Logo '.$name,
            'initials' => $this->initials($name),
            'primary_color' => $primary,
            'secondary_color' => $secondary,
            'accent_color' => $accent,
            'header_background_color' => $headerBackground,
            'page_background_color' => $pageBackground,
            'surface_color' => $surface,
            'text_color' => $text,
            'muted_color' => $muted,
            'border_color' => $border,
            'button_text_color' => $this->contrastColor($primary),
            'sender_name' => $name,
            'factory_name' => 'Peter Tecnet',
            'is_factory' => $isFactory,
        ];
    }

    private function safeBaseUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = Str::lower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    private function assetUrl(mixed $value, ?string $appUrl): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $scheme = Str::lower((string) parse_url($value, PHP_URL_SCHEME));

            return in_array($scheme, ['http', 'https'], true) ? $value : null;
        }

        if (! $appUrl || Str::startsWith($value, '//')) {
            return null;
        }

        return $appUrl.'/'.ltrim($value, '/');
    }

    private function color(mixed $candidate, string $fallback): string
    {
        $candidate = trim((string) $candidate);

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $candidate) === 1) {
            return Str::lower($candidate);
        }

        if (preg_match('/^#[0-9a-fA-F]{3}$/', $candidate) === 1) {
            return '#'.Str::lower(
                $candidate[1].$candidate[1]
                .$candidate[2].$candidate[2]
                .$candidate[3].$candidate[3]
            );
        }

        return Str::lower($fallback);
    }

    private function contrastColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return '#ffffff';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminance = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

        return $luminance > 170 ? '#111827' : '#ffffff';
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $letters = collect($parts)
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return $letters !== '' ? $letters : 'PT';
    }
}
