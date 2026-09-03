<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    private const PRODUCT_NAMES = [
        'cutinapp', 'rasoio', 'nexus', 'plat', 'laora', 'payflow', 'inkap', 'camquick',
    ];

    public function test_openapi_document_is_valid_json_and_application_scoped(): void
    {
        $path = base_path('openapi/platform-v1.json');
        $this->assertFileExists($path);

        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringStartsWith('3.', (string) ($document['openapi'] ?? ''));
        $this->assertNotEmpty($document['paths'] ?? []);

        foreach (array_keys($document['paths']) as $route) {
            $this->assertStringStartsWith('/api/v1/apps/{application}/', $route);
            foreach (self::PRODUCT_NAMES as $product) {
                $this->assertStringNotContainsString('/'.$product.'/', strtolower($route));
            }
        }
    }

    public function test_openapi_covers_critical_generic_commerce_and_payout_contracts(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/platform-v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $paths = $document['paths'] ?? [];

        $this->assertArrayHasKey('/api/v1/apps/{application}/commerce/checkout', $paths);
        $this->assertArrayHasKey('/api/v1/apps/{application}/commerce/orders/{publicId}/sync-payment', $paths);
        $this->assertArrayHasKey('/api/v1/apps/{application}/organizations/{organizationId}/payouts', $paths);
        $this->assertArrayHasKey('/api/v1/apps/{application}/organizations/{organizationId}/payouts/{payoutId}/cancel', $paths);
    }
}
