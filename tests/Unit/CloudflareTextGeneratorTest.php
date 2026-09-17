<?php

namespace Tests\Unit;

use App\Domain\Creative\Services\CloudflareTextGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CloudflareTextGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('creative.cloudflare.account_id', 'account-test');
        config()->set('creative.cloudflare.api_token', 'token-test');
        config()->set('creative.cloudflare.text_model', '@cf/meta/llama-3.1-8b-instruct-fp8');
        config()->set('creative.cloudflare.text_daily_request_budget', 1000);
        config()->set('creative.cloudflare.text_per_user_daily_limit', 100);
    }

    public function test_it_generates_text_using_the_configured_model(): void
    {
        Http::fake([
            'https://api.cloudflare.com/*' => Http::response([
                'result' => [
                    'response' => 'Descrição melhorada e objetiva.',
                    'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 8],
                ],
            ]),
        ]);

        $result = (new CloudflareTextGenerator)->generate(
            'Escreva em português.',
            'Melhore a descrição do item.',
            99,
            5,
        );

        $this->assertSame('Descrição melhorada e objetiva.', $result['text']);
        $this->assertSame('@cf/meta/llama-3.1-8b-instruct-fp8', $result['model']);
        $this->assertSame('cloudflare_workers_ai', $result['provider']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/@cf/meta/llama-3.1-8b-instruct-fp8')
                && data_get($request->data(), 'messages.0.role') === 'system'
                && data_get($request->data(), 'messages.1.role') === 'user';
        });
    }
}
