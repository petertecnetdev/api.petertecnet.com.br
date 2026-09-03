<?php

use App\Services\Payments\Gateways\MercadoPagoPaymentGateway;

return [
    'payments' => [
        'default' => env('COMMERCE_PAYMENT_PROVIDER', 'mercadopago'),

        'providers' => [
            'mercadopago' => MercadoPagoPaymentGateway::class,
        ],
    ],
];
