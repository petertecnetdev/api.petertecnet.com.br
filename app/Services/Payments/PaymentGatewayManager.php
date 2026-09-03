<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGateway;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class PaymentGatewayManager
{
    public function __construct(private readonly Container $container) {}

    public function default(): PaymentGateway
    {
        return $this->for((string) config('commerce.payments.default', 'mercadopago'));
    }

    public function for(string $provider): PaymentGateway
    {
        $provider = strtolower(trim($provider));
        $class = config("commerce.payments.providers.{$provider}");

        if (! is_string($class) || $class === '') {
            throw new InvalidArgumentException("Payment provider [{$provider}] is not configured.");
        }

        $gateway = $this->container->make($class);

        if (! $gateway instanceof PaymentGateway) {
            throw new InvalidArgumentException("Payment provider [{$provider}] must implement " . PaymentGateway::class . '.');
        }

        return $gateway;
    }
}
