<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RequestCorrelationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(\App\Http\Middleware\RequestId::class)
            ->get('/_test/request-correlation', fn () => response()->json(['ok' => true]));
    }

    public function test_it_preserves_valid_request_and_correlation_ids(): void
    {
        $response = $this->getJson('/_test/request-correlation', [
            'X-Request-ID' => 'request-12345678',
            'X-Correlation-ID' => 'correlation-12345678',
        ]);

        $response->assertOk()
            ->assertHeader('X-Request-ID', 'request-12345678')
            ->assertHeader('X-Correlation-ID', 'correlation-12345678');
    }

    public function test_it_generates_safe_ids_when_incoming_values_are_invalid(): void
    {
        $response = $this->getJson('/_test/request-correlation', [
            'X-Request-ID' => "invalid\nheader",
            'X-Correlation-ID' => 'short',
        ]);

        $response->assertOk();

        $requestId = $response->headers->get('X-Request-ID');
        $correlationId = $response->headers->get('X-Correlation-ID');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $requestId);
        $this->assertSame($requestId, $correlationId);
    }
}
