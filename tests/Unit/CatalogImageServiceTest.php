<?php

namespace Tests\Unit;

use App\Domain\Creative\Services\CatalogImageService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CatalogImageServiceTest extends TestCase
{
    public function test_prompt_uses_only_supplied_catalog_facts_and_keeps_text_out_of_image(): void
    {
        $service = (new ReflectionClass(CatalogImageService::class))->newInstanceWithoutConstructor();

        $prompt = $service->buildPrompt([
            'subject' => 'Cappuccino',
            'description' => 'Café espresso com leite vaporizado',
            'item_type' => 'product',
            'category' => 'Bebidas',
            'brand' => null,
            'catalog_name' => null,
        ]);

        self::assertStringContainsString('Subject: Cappuccino', $prompt);
        self::assertStringContainsString('use only these factual details', $prompt);
        self::assertStringContainsString('Do not invent specific ingredients', $prompt);
        self::assertStringContainsString('Do not render words, letters, prices, labels, logos, watermarks', $prompt);
        self::assertLessThanOrEqual(2048, mb_strlen($prompt));
    }

    public function test_http_boundary_cannot_take_application_id_from_payload(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Domain/Creative/Http/Controllers/CatalogImageController.php');

        self::assertIsString($controller);
        self::assertStringContainsString('$this->context->id()', $controller);
        self::assertStringNotContainsString("'application_id'", $controller);
        self::assertStringNotContainsString('$request->application_id', $controller);
        self::assertStringNotContainsString('$request->input(\'application_id\')', $controller);
    }
}
