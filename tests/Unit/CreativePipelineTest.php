<?php

namespace Tests\Unit;

use App\Domain\Creative\Services\CreativeBriefBuilder;
use App\Domain\Creative\Services\CreativeCandidatePlanner;
use App\Domain\Creative\Services\CreativeQualityEvaluator;
use App\Domain\Creative\Services\CreativeRegenerationService;
use App\Domain\Creative\Services\CreativeSafeZonePlanner;
use Tests\TestCase;

final class CreativePipelineTest extends TestCase
{
    public function test_safe_zones_are_format_aware(): void
    {
        $planner = new CreativeSafeZonePlanner();

        $story = $planner->forFormat('story');
        $cover = $planner->forFormat('cover');

        $this->assertArrayHasKey('headline', $story);
        $this->assertArrayHasKey('meta', $story);
        $this->assertArrayHasKey('cta', $story);
        $this->assertNotSame($story, $cover);
        $this->assertStringContainsString('negative-space', $planner->prompt($story));
    }

    public function test_candidate_planner_limits_and_varies_candidates(): void
    {
        $planner = new CreativeCandidatePlanner();
        $candidates = $planner->prompts('base prompt', 9, 'editorial');

        $this->assertCount(4, $candidates);
        $this->assertSame('editorial', $candidates[0]['variation']);
        $this->assertStringContainsString('CANDIDATE DIRECTION', $candidates[0]['prompt']);
    }

    public function test_brief_never_allows_model_to_render_canonical_copy(): void
    {
        $zones = (new CreativeSafeZonePlanner())->forFormat('story');
        $brief = (new CreativeBriefBuilder())->build([
            'subject' => 'Quarta Sem Freio',
            'production_name' => 'La Fyesta Pub',
            'brand_colors' => ['#ff00aa'],
        ], [
            'style_key' => 'neon',
            'intensity_key' => 'impactful',
            'format_key' => 'story',
            'ratio' => '9:16',
        ], $zones);

        $this->assertFalse($brief['rules']['render_text']);
        $this->assertFalse($brief['rules']['render_prices']);
        $this->assertFalse($brief['rules']['render_dates']);
        $this->assertTrue($brief['rules']['preserve_typography_space']);
    }

    public function test_regeneration_adds_only_the_requested_art_direction_delta(): void
    {
        $service = new CreativeRegenerationService();
        $prompt = $service->apply('base', 'more_premium');

        $this->assertStringStartsWith('base', $prompt);
        $this->assertStringContainsString('REGENERATION REQUEST', $prompt);
        $this->assertSame('base', $service->apply('base', null));
    }

    public function test_quality_guard_rejects_invalid_or_tiny_assets(): void
    {
        config(['creative.quality.minimum_score' => 70]);
        $evaluation = (new CreativeQualityEvaluator())->evaluate([
            'image' => base64_encode('tiny'),
            'mime_type' => 'text/plain',
        ], [
            'safe_zones' => [],
            'rules' => ['avoid_ai_artifacts' => false],
        ], 'prompt without suppression rule');

        $this->assertFalse($evaluation['approved']);
        $this->assertLessThan(70, $evaluation['score']);
        $this->assertContains('invalid_image_mime', $evaluation['issues']);
    }
}
