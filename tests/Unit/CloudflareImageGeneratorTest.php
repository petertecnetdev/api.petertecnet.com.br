<?php

namespace Tests\Unit;

use App\Domain\Creative\Services\CloudflareImageGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CloudflareImageGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('creative.cloudflare.account_id', 'account-test');
        config()->set('creative.cloudflare.api_token', 'token-test');
        config()->set('creative.cloudflare.daily_request_budget', 1000);
        config()->set('creative.cloudflare.per_user_daily_limit', 100);
    }

    public function test_event_profile_can_force_the_premium_model_and_steps(): void
    {
        Http::fake([
            'https://api.cloudflare.com/*' => Http::response([
                'result' => ['image' => base64_encode('fake-jpeg')],
            ]),
        ]);

        $result = (new CloudflareImageGenerator)->generate(
            'Premium nightlife background artwork. No readable text.',
            99,
            7,
            [
                'model' => '@cf/black-forest-labs/flux-2-dev',
                'steps' => 16,
                'width' => 1080,
                'height' => 1920,
            ]
        );

        $this->assertSame('@cf/black-forest-labs/flux-2-dev', $result['model']);
        $this->assertSame(16, $result['requested_steps']);
        $this->assertSame(1080, $result['requested_width']);
        $this->assertSame(1920, $result['requested_height']);

        Http::assertSent(fn ($request) => str_contains(
            $request->url(),
            '/ai/run/@cf/black-forest-labs/flux-2-dev'
        ));
    }

    public function test_preview_profile_forces_klein_four_steps_and_accepts_references(): void
    {
        Http::fake([
            'https://api.cloudflare.com/*' => Http::response([
                'result' => ['image' => base64_encode(str_repeat('x', 100))],
            ]),
        ]);

        $reference = 'data:image/jpeg;base64,'.base64_encode(str_repeat('r', 128));
        $result = (new CloudflareImageGenerator)->generate(
            'Premium preview background. Do not render any readable words.',
            99,
            7,
            [
                'model' => '@cf/black-forest-labs/flux-2-klein-4b',
                'steps' => 20,
                'width' => 540,
                'height' => 960,
                'reference_images' => [$reference],
            ]
        );

        $this->assertSame('@cf/black-forest-labs/flux-2-klein-4b', $result['model']);
        $this->assertSame(4, $result['requested_steps']);
        $this->assertSame(540, $result['requested_width']);
        $this->assertSame(960, $result['requested_height']);
        $this->assertSame(1, $result['reference_count']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/flux-2-klein-4b'));
    }

    public function test_invalid_reference_images_are_ignored(): void
    {
        Http::fake([
            'https://api.cloudflare.com/*' => Http::response([
                'result' => ['image' => base64_encode(str_repeat('x', 100))],
            ]),
        ]);

        $result = (new CloudflareImageGenerator)->generate('Artwork', 99, 7, [
            'model' => '@cf/black-forest-labs/flux-2-dev',
            'reference_images' => ['not-valid-base64'],
        ]);

        $this->assertSame(0, $result['reference_count']);
    }

    public function test_default_profile_keeps_existing_model_fallback(): void
    {
        config()->set('creative.cloudflare.quality_model', '');
        config()->set('creative.cloudflare.model', '@cf/black-forest-labs/flux-1-schnell');
        config()->set('creative.cloudflare.steps', 4);

        Http::fake([
            'https://api.cloudflare.com/*' => Http::response([
                'result' => ['image' => base64_encode('fake-jpeg')],
            ]),
        ]);

        $result = (new CloudflareImageGenerator)->generate('Generic artwork', 100, 7);

        $this->assertSame('@cf/black-forest-labs/flux-1-schnell', $result['model']);
        $this->assertSame(4, $result['requested_steps']);
    }
}
