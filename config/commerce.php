<?php

use App\Services\Payments\Gateways\MercadoPagoPaymentGateway;

return [
    'payments' => [
        'default' => env('COMMERCE_PAYMENT_PROVIDER', 'mercadopago'),
        'pending_expiration_minutes' => (int) env('COMMERCE_PENDING_EXPIRATION_MINUTES', 30),

        'providers' => [
            'mercadopago' => MercadoPagoPaymentGateway::class,
        ],
    ],
];
