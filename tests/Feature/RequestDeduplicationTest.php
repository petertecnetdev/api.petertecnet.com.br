<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Services\RequestDeduplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class RequestDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_equivalent_mutation_is_executed_only_once(): void
    {
        $application = Application::query()->create([
            'name' => 'Future Commerce',
            'slug' => 'future-commerce',
            'is_active' => true,
            'capabilities' => ['commerce'],
        ]);

        $service = app(RequestDeduplicationService::class);
        $executions = 0;
        $next = function () use (&$executions) {
            $executions++;
            return response()->json(['created' => true, 'sequence' => $executions], 201);
        };

        $first = $this->request(['amount' => 120.50], 'retry-safe-001');
        $firstResponse = $service->handle($first, $next, $application);

        $second = $this->request(['amount' => 120.50], 'retry-safe-001');
        $secondResponse = $service->handle($second, $next, $application);

        $this->assertSame(1, $executions);
        $this->assertSame(201, $firstResponse->getStatusCode());
        $this->assertSame('false', $firstResponse->headers->get('Idempotency-Replayed'));
        $this->assertSame(201, $secondResponse->getStatusCode());
        $this->assertSame('true', $secondResponse->headers->get('Idempotency-Replayed'));
        $this->assertSame($firstResponse->getContent(), $secondResponse->getContent());
        $this->assertDatabaseCount('request_deduplication_records', 1);
    }

    public function test_same_key_with_different_payload_is_rejected(): void
    {
        $application = Application::query()->create([
            'name' => 'Future Finance',
            'slug' => 'future-finance',
            'is_active' => true,
            'capabilities' => ['finance'],
        ]);

        $service = app(RequestDeduplicationService::class);
        $executions = 0;
        $next = function () use (&$executions) {
            $executions++;
            return response()->json(['ok' => true], 201);
        };

        $service->handle($this->request(['amount' => 100], 'same-key'), $next, $application);
        $conflict = $service->handle($this->request(['amount' => 999], 'same-key'), $next, $application);

        $this->assertSame(1, $executions);
        $this->assertSame(409, $conflict->getStatusCode());
        $this->assertStringContainsString('IDEMPOTENCY_CONFLICT', (string) $conflict->getContent());
    }

    public function test_same_key_is_isolated_between_applications(): void
    {
        $firstApplication = Application::query()->create([
            'name' => 'Application One',
            'slug' => 'application-one',
            'is_active' => true,
            'capabilities' => ['commerce'],
        ]);
        $secondApplication = Application::query()->create([
            'name' => 'Application Two',
            'slug' => 'application-two',
            'is_active' => true,
            'capabilities' => ['commerce'],
        ]);

        $service = app(RequestDeduplicationService::class);
        $executions = 0;
        $next = function () use (&$executions) {
            $executions++;
            return response()->json(['sequence' => $executions], 201);
        };

        $service->handle($this->request(['amount' => 50], 'shared-client-key'), $next, $firstApplication);
        $service->handle($this->request(['amount' => 50], 'shared-client-key'), $next, $secondApplication);

        $this->assertSame(2, $executions);
        $this->assertDatabaseCount('request_deduplication_records', 2);
    }

    private function request(array $payload, string $key): Request
    {
        $request = Request::create('/api/v1/apps/future/commerce/checkout', 'POST', $payload);
        $request->headers->set('Idempotency-Key', $key);
        $request->headers->set('Authorization', 'Bearer test-principal');

        return $request;
    }
}
