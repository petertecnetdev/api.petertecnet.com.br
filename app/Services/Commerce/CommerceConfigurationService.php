<?php

namespace App\Services\Commerce;

use App\Models\Establishment;
use App\Services\Payments\PaymentGatewayManager;

class CommerceConfigurationService
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    public function forEstablishment(Establishment $establishment): array
    {
        $gateway = $this->gateways->default();
        $providerReady = $gateway->isConfigured();
        $supportedMethods = $gateway->supportedMethods();
        $configured = $this->json(
            $establishment->payment_methods,
            $providerReady ? $supportedMethods : []
        );
        $paymentMethods = array_values(array_intersect($configured, $supportedMethods));

        return [
            'available' => (bool) ($establishment->ordering_enabled ?? true)
                && (bool) ($establishment->accepting_orders ?? true)
                && $providerReady,
            'unavailable_reason' => ! $providerReady ? 'Pagamento online ainda não foi configurado.' : null,
            'payment_provider' => $gateway->name(),
            'payment_methods' => $paymentMethods ?: ($providerReady ? $supportedMethods : []),
            'fulfillment' => [
                'pickup' => (bool) ($establishment->pickup_enabled ?? true),
                'delivery' => (bool) ($establishment->delivery_enabled ?? true),
            ],
            'delivery_fee' => (float) ($establishment->delivery_fee ?? 0),
            'minimum_order' => (float) ($establishment->minimum_order ?? 0),
        ];
    }

    private function json(mixed $value, array $fallback): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $fallback;
    }
}
