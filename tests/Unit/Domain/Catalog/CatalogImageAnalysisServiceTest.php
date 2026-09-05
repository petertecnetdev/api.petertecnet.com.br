<?php

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\Services\CatalogImageAnalysisService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogImageAnalysisServiceTest extends TestCase
{
    public function test_it_extracts_structured_catalog_items_from_image_response(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');
        config()->set('services.openai.catalog_vision_model', 'gpt-5-mini');
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'items' => [[
                                'name' => 'X-Bacon', 'description' => 'Hambúrguer, bacon e queijo',
                                'price' => 28, 'category' => 'Lanches', 'subcategory' => null,
                                'brand' => null, 'sku' => null, 'type' => 'product',
                                'confidence' => 0.98, 'source_text' => 'X-Bacon R$ 28,00',
                            ]],
                            'warnings' => [],
                        ], JSON_UNESCAPED_UNICODE),
                    ]],
                ]],
            ], 200),
        ]);

        $image = UploadedFile::fake()->create('cardapio.jpg', 32, 'image/jpeg');
        $result = app(CatalogImageAnalysisService::class)->analyze([$image]);

        $this->assertCount(1, $result['items']);
        $this->assertSame('X-Bacon', $result['items'][0]['name']);
        $this->assertSame(28.0, $result['items'][0]['price']);
        $this->assertSame('Lanches', $result['items'][0]['category']);
        $this->assertSame(0.98, $result['items'][0]['confidence']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/responses'
            && data_get($request->data(), 'text.format.type') === 'json_schema'
            && data_get($request->data(), 'text.format.strict') === true
            && data_get($request->data(), 'store') === false);
    }
}
