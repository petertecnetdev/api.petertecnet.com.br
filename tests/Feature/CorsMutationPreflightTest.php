<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CorsMutationPreflightTest extends TestCase
{
    #[DataProvider('criticalMutationRoutes')]
    public function test_cutinapp_critical_mutations_accept_idempotency_preflight(string $path): void
    {
        $response = $this->call('OPTIONS', $path, [], [], [], [
            'HTTP_ORIGIN' => 'https://cutinapp.petertecnet.com.br',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, content-type, idempotency-key, x-request-id, x-peter-app, x-frontend-page',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 204]);
        $this->assertSame(
            'https://cutinapp.petertecnet.com.br',
            $response->headers->get('Access-Control-Allow-Origin')
        );

        $allowed = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));
        foreach (['authorization', 'content-type', 'idempotency-key', 'x-request-id', 'x-peter-app', 'x-frontend-page'] as $header) {
            $this->assertStringContainsString($header, $allowed, "CORS preflight did not allow {$header} for {$path}");
        }
    }

    public function test_diagnostics_and_idempotency_headers_are_exposed_to_browser(): void
    {
        $exposed = array_map('strtolower', config('cors.exposed_headers', []));

        foreach (['x-request-id', 'server-timing', 'idempotency-status', 'idempotency-replayed', 'retry-after'] as $header) {
            $this->assertContains($header, $exposed);
        }
    }

    public static function criticalMutationRoutes(): array
    {
        return [
            'organization create' => ['/api/v1/apps/cutinapp/organizations'],
            'event create' => ['/api/v1/apps/cutinapp/events'],
            'ticket create' => ['/api/v1/apps/cutinapp/tickets'],
            'courtesy claim' => ['/api/v1/apps/cutinapp/passes/claim/1'],
            'checkin' => ['/api/v1/apps/cutinapp/checkin'],
            'checkout' => ['/api/v1/apps/cutinapp/commerce/checkout'],
        ];
    }
}
