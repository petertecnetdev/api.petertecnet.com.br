<?php

namespace Tests\Unit;

use App\Http\Middleware\HandleImpersonation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class HandleImpersonationRedactionTest extends TestCase
{
    public function test_it_recursively_redacts_sensitive_context_without_losing_scope_identifiers(): void
    {
        $middleware = (new ReflectionClass(HandleImpersonation::class))->newInstanceWithoutConstructor();
        $redact = new ReflectionMethod(HandleImpersonation::class, 'redact');
        $redact->setAccessible(true);

        $context = [
            'application_id' => 17,
            'establishment_id' => 42,
            'api-key' => 'secret-api-key',
            'nested' => [
                'pix.key' => 'user@example.com',
                'bank account' => '123456',
                'refreshToken' => 'refresh-secret',
                'credential_id' => 'credential-secret',
                'routing-number' => '001122',
                'swift_code' => 'ABCDBRSP',
                'safe_value' => 'visible',
            ],
        ];

        $result = $redact->invoke($middleware, $context);

        $this->assertSame(17, $result['application_id']);
        $this->assertSame(42, $result['establishment_id']);
        $this->assertSame('[REDACTED]', $result['api-key']);
        $this->assertSame('[REDACTED]', $result['nested']['pix.key']);
        $this->assertSame('[REDACTED]', $result['nested']['bank account']);
        $this->assertSame('[REDACTED]', $result['nested']['refreshToken']);
        $this->assertSame('[REDACTED]', $result['nested']['credential_id']);
        $this->assertSame('[REDACTED]', $result['nested']['routing-number']);
        $this->assertSame('[REDACTED]', $result['nested']['swift_code']);
        $this->assertSame('visible', $result['nested']['safe_value']);
    }
}
