<?php

namespace Tests\Unit;

use App\Domain\Creative\Services\CreativeDirectorService;
use Tests\TestCase;

final class CreativeDirectorServiceTest extends TestCase
{
    public function test_automatic_direction_infers_electronic_event_and_cover_dimensions(): void
    {
        $service = new CreativeDirectorService;

        $direction = $service->resolveEventDirection([
            'subject' => 'I Love Eltro',
            'description' => 'Noite eletrônica com DJ, dois ambientes e muita energia.',
            'style' => 'automatic',
            'intensity' => 'impactful',
            'format' => 'cover',
        ]);

        $this->assertSame('electronic', $direction['style_key']);
        $this->assertSame('impactful', $direction['intensity_key']);
        $this->assertSame('16:9', $direction['ratio']);
        $this->assertSame(1600, $direction['width']);
        $this->assertSame(900, $direction['height']);
    }

    public function test_event_prompt_stays_inside_provider_limit_and_protects_canonical_text(): void
    {
        $service = new CreativeDirectorService;

        $prompt = $service->composeEventPrompt(
            'Real event: I Love Eltro. La Fyesta Pub. Food, music and drinks. Two environments.',
            [
                'subject' => 'I Love Eltro',
                'description' => 'Noite eletrônica com DJ Marco Roger.',
                'style' => 'neon',
                'intensity' => 'balanced',
                'format' => 'cover',
                'artist' => 'DJ Marco Roger',
                'brand_context' => 'La Fyesta Pub · Food | Music | Drinks',
                'promotions' => ['Mulheres free com nome na lista', 'Homens R$ 10 com nome na lista'],
                'featured_items' => ['combo de vodka', 'narguilé', 'porção de frango', 'porção de batata'],
            ]
        );

        $this->assertLessThanOrEqual(2048, mb_strlen($prompt));
        $this->assertStringContainsString('senior advertising art director', $prompt);
        $this->assertStringContainsString('wide 16:9 event-page hero composition', $prompt);
        $this->assertStringContainsString('Do NOT render any readable words', $prompt);
        $this->assertStringContainsString('fake sponsors', $prompt);
    }

    public function test_presets_are_generic_and_reusable_by_clients(): void
    {
        $service = new CreativeDirectorService;
        $presets = $service->presets();

        $this->assertNotEmpty($presets['styles']);
        $this->assertNotEmpty($presets['intensities']);
        $this->assertNotEmpty($presets['formats']);
        $this->assertContains('automatic', array_column($presets['styles'], 'key'));
        $this->assertContains('og', array_column($presets['formats'], 'key'));
    }
}
