<?php

namespace Tests\Unit;

use App\Services\Media\ImageMediaService;
use PHPUnit\Framework\TestCase;

class ImageMediaServiceTest extends TestCase
{
    public function test_it_classifies_image_quality_by_width(): void
    {
        $service = new ImageMediaService();

        $this->assertSame('excellent', $service->quality(1080)['level']);
        $this->assertSame('good', $service->quality(900)['level']);
        $this->assertSame('acceptable', $service->quality(720)['level']);
        $this->assertSame('low', $service->quality(480)['level']);
        $this->assertSame('very_low', $service->quality(479)['level']);
    }

    public function test_it_derives_variants_from_any_pipeline_primary_path(): void
    {
        $service = new ImageMediaService();
        $variants = $service->variantPaths('images/events/abc/hero.webp');

        $this->assertSame('images/events/abc/original.webp', $variants['original']);
        $this->assertSame('images/events/abc/card.webp', $variants['card']);
        $this->assertSame('images/events/abc/background.webp', $variants['background']);
        $this->assertSame('images/events/abc/og.webp', $variants['og']);
    }

    public function test_legacy_image_paths_remain_compatible(): void
    {
        $service = new ImageMediaService();
        $variants = $service->variantPaths('images/events/legacy.webp');

        $this->assertSame('images/events/legacy.webp', $variants['original']);
        $this->assertSame('images/events/legacy.webp', $variants['card']);
        $this->assertSame('images/events/legacy.webp', $variants['og']);
    }
}
