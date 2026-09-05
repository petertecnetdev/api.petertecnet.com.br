<?php

namespace Tests\Unit;

use App\Events\EcosystemUpdated;
use App\Services\ResilientRealtimePublisher;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ResilientRealtimePublisherTest extends TestCase
{
    public function test_it_preserves_primary_operation_when_realtime_dispatch_fails(): void
    {
        $event = new EcosystemUpdated(['dashboard'], 'test');
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($event)
            ->willThrowException(new RuntimeException('provider unavailable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Realtime broadcast failed; primary operation preserved.',
                $this->callback(fn (array $context): bool =>
                    ($context['operation'] ?? null) === 'telemetry'
                    && ($context['event'] ?? null) === EcosystemUpdated::class
                    && ($context['exception'] ?? null) === RuntimeException::class
                )
            );

        $publisher = new ResilientRealtimePublisher($dispatcher, $logger);

        $this->assertFalse($publisher->publish($event, ['operation' => 'telemetry']));
    }

    public function test_it_reports_success_when_realtime_dispatch_succeeds(): void
    {
        $event = new EcosystemUpdated(['dashboard'], 'test');
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->once())->method('dispatch')->with($event);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $publisher = new ResilientRealtimePublisher($dispatcher, $logger);

        $this->assertTrue($publisher->publish($event));
    }
}
