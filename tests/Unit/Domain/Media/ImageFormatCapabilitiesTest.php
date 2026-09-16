<?php

namespace Tests\Unit\Domain\Media;

use App\Domain\Media\Services\ImageFormatCapabilities;
use PHPUnit\Framework\TestCase;

final class ImageFormatCapabilitiesTest extends TestCase
{
    public function test_png_source_is_preserved_when_modern_formats_are_not_requested(): void
    {
        $service = new ImageFormatCapabilities();

        self::assertSame('png', $service->resolve('auto', 'image/png,image/*', 'image/png'));
    }

    public function test_jpeg_is_default_for_non_png_sources_without_modern_accept_header(): void
    {
        $service = new ImageFormatCapabilities();

        self::assertSame('jpeg', $service->resolve('auto', 'image/jpeg,image/*', 'image/jpeg'));
    }

    public function test_mime_mapping_is_shared_and_stable(): void
    {
        $service = new ImageFormatCapabilities();

        self::assertSame('image/avif', $service->mime('avif'));
        self::assertSame('image/webp', $service->mime('webp'));
        self::assertSame('image/png', $service->mime('png'));
        self::assertSame('image/jpeg', $service->mime('jpeg'));
    }

    public function test_capability_contract_exposes_standard_responsive_widths(): void
    {
        $service = new ImageFormatCapabilities();
        $capabilities = $service->all();

        self::assertSame([320, 480, 640, 960, 1280, 1600], $capabilities['widths']);
        self::assertArrayHasKey('avif', $capabilities);
        self::assertArrayHasKey('webp', $capabilities);
    }
}
