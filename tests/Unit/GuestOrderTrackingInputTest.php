<?php

namespace Tests\Unit;

use App\Domain\Commerce\Services\GuestOrderTrackingService;
use App\Services\MercadoPagoService;
use App\Support\ApplicationContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class GuestOrderTrackingInputTest extends TestCase
{
    public function test_oversized_normalized_phone_is_rejected_before_order_lookup(): void
    {
        $service = new GuestOrderTrackingService(
            $this->createMock(ApplicationContext::class),
            $this->createMock(MercadoPagoService::class),
        );

        $this->expectException(NotFoundHttpException::class);

        $service->track(1, str_repeat('9', 16));
    }
}
