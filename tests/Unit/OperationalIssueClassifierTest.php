<?php

namespace Tests\Unit;

use App\Services\Operations\OperationalIssueClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OperationalIssueClassifierTest extends TestCase
{
    #[DataProvider('categoryCases')]
    public function test_it_classifies_operational_failures(array $event, string $expected): void
    {
        $classifier = new OperationalIssueClassifier();
        $this->assertSame($expected, $classifier->category($event));
    }

    public static function categoryCases(): array
    {
        return [
            'authentication' => [['http_status' => 401, 'message' => 'Unauthenticated'], 'authentication'],
            'authorization' => [['http_status' => 403, 'message' => 'Forbidden'], 'authorization'],
            'validation' => [['http_status' => 422, 'message' => 'Validation failed'], 'validation'],
            'rate limit' => [['http_status' => 429, 'message' => 'Too many requests'], 'rate_limit'],
            'database' => [['http_status' => 500, 'message' => 'SQLSTATE[23000] constraint violation'], 'database'],
            'timeout' => [['http_status' => 500, 'message' => 'Request timed out'], 'timeout'],
            'dependency' => [['http_status' => 502, 'message' => 'Connection refused by gateway'], 'dependency'],
            'server exception' => [['http_status' => 500, 'message' => 'Unexpected exception'], 'server_exception'],
        ];
    }

    public function test_it_infers_shared_domain_instead_of_application_name(): void
    {
        $classifier = new OperationalIssueClassifier();

        $this->assertSame('payments', $classifier->domain(['route' => '/api/payments/pix']));
        $this->assertSame('scheduling', $classifier->domain(['route_name' => 'appointments.reserve']));
        $this->assertSame('identity', $classifier->domain(['path' => '/auth/login']));
    }

    public function test_critical_payment_server_failure_receives_higher_priority(): void
    {
        $classifier = new OperationalIssueClassifier();
        $event = [
            'severity' => 'critical',
            'outcome' => 'error',
            'http_status' => 500,
            'message' => 'Payment provider failed',
            'route' => '/api/payments',
        ];
        $event['category'] = $classifier->category($event);
        $event['domain'] = $classifier->domain($event);

        $score = $classifier->impactScore($event, 40, 8, 2);

        $this->assertGreaterThanOrEqual(80, $score);
        $this->assertSame('P0', $classifier->priority($score));
    }
}
