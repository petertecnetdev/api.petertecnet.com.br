<?php

namespace Tests\Unit;

use App\Domain\DeveloperPlatform\Models\ApiKey;
use App\Domain\DeveloperPlatform\Services\WebhookUrlGuard;
use InvalidArgumentException;
use Tests\TestCase;

class DeveloperPlatformContractTest extends TestCase
{
    public function test_openapi_document_is_valid_and_declares_isolated_servers(): void
    {
        $document = json_decode(
            file_get_contents(public_path('openapi.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame('1.0.0', $document['info']['version']);
        $this->assertSame('https://api.petertecnet.com.br/api/v1', $document['servers'][0]['url']);
        $this->assertSame('https://api.petertecnet.com.br/api/sandbox/v1', $document['servers'][1]['url']);
        $this->assertArrayHasKey('/establishments', $document['paths']);
        $this->assertArrayHasKey('/items', $document['paths']);
        $this->assertSame('X-API-Key', $document['components']['securitySchemes']['ApiKeyAuth']['name']);
    }

    public function test_api_key_hash_is_hidden_from_serialization(): void
    {
        $key = new ApiKey([
            'name' => 'Test',
            'key_prefix' => 'pt_test_example',
            'key_hash' => str_repeat('a', 64),
        ]);

        $serialized = $key->toArray();

        $this->assertArrayNotHasKey('key_hash', $serialized);
        $this->assertSame('pt_test_example', $serialized['key_prefix']);
    }

    public function test_webhook_guard_rejects_local_and_private_targets(): void
    {
        $guard = new WebhookUrlGuard();

        foreach (['http://example.com/hook', 'https://localhost/hook', 'https://127.0.0.1/hook', 'https://10.0.0.5/hook'] as $url) {
            try {
                $guard->assertSafe($url);
                $this->fail("Unsafe webhook URL was accepted: {$url}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_public_scope_catalog_is_generic_and_reusable(): void
    {
        $scopes = config('developer.scopes');

        $this->assertArrayHasKey('establishments:read', $scopes);
        $this->assertArrayHasKey('catalog:read', $scopes);
        $this->assertArrayHasKey('orders:write', $scopes);
        $this->assertArrayHasKey('appointments:write', $scopes);
        $this->assertArrayHasKey('webhooks:manage', $scopes);

        foreach (array_keys($scopes) as $scope) {
            $this->assertStringNotContainsString('nexus', strtolower($scope));
            $this->assertStringNotContainsString('cutinapp', strtolower($scope));
            $this->assertStringNotContainsString('rasoio', strtolower($scope));
        }
    }
}
