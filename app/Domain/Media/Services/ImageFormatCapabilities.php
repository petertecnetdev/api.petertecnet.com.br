<?php

namespace App\Domain\Media\Services;

final class ImageFormatCapabilities
{
    /**
     * Shared image-delivery capabilities for every Peter Tecnet application.
     *
     * @return array{avif: bool, webp: bool, widths: array<int, int>}
     */
    public function all(): array
    {
        return [
            'avif' => $this->supportsAvif(),
            'webp' => $this->supportsWebp(),
            'widths' => [320, 480, 640, 960, 1280, 1600],
        ];
    }

    public function supportsWebp(): bool
    {
        if (function_exists('imagewebp')) {
            return true;
        }

        return class_exists(\Imagick::class)
            && in_array('WEBP', \Imagick::queryFormats('WEBP'), true);
    }

    public function supportsAvif(): bool
    {
        if (function_exists('imageavif')) {
            return true;
        }

        return class_exists(\Imagick::class)
            && in_array('AVIF', \Imagick::queryFormats('AVIF'), true);
    }

    public function resolve(string $requested, string $accept, string $sourceMime): string
    {
        $requested = strtolower(trim($requested));
        $accept = strtolower($accept);
        $sourceMime = strtolower($sourceMime);

        if ($requested !== 'auto') {
            if ($requested === 'avif' && ! $this->supportsAvif()) {
                return $this->supportsWebp() ? 'webp' : 'jpeg';
            }

            if ($requested === 'webp' && ! $this->supportsWebp()) {
                return 'jpeg';
            }

            return $requested;
        }

        if (str_contains($accept, 'image/avif') && $this->supportsAvif()) {
            return 'avif';
        }

        if (str_contains($accept, 'image/webp') && $this->supportsWebp()) {
            return 'webp';
        }

        return str_contains($sourceMime, 'png') ? 'png' : 'jpeg';
    }

    public function mime(string $format): string
    {
        return match ($format) {
            'avif' => 'image/avif',
            'webp' => 'image/webp',
            'png' => 'image/png',
            default => 'image/jpeg',
        };
    }
}
