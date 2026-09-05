<?php

return [
    'provider' => env('SUBSCRIPTION_PROVIDER', 'mercadopago'),
    'currency' => 'BRL',
    'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 3),
    'checkout_back_url' => env('SUBSCRIPTION_CHECKOUT_BACK_URL', 'https://petertecnet.com.br/assinatura/retorno'),

    'plans' => [
        'monthly' => [
            'name' => 'Mensal',
            'amount' => 14.90,
            'frequency' => 1,
            'frequency_type' => 'months',
            'trial_days' => 0,
        ],
        'semiannual' => [
            'name' => 'Semestral',
            'amount' => 74.90,
            'frequency' => 6,
            'frequency_type' => 'months',
            'trial_days' => 0,
        ],
        'annual' => [
            'name' => 'Anual',
            'amount' => 119.90,
            'frequency' => 12,
            'frequency_type' => 'months',
            'trial_days' => 30,
        ],
    ],

    'applications' => [
        'rasoio' => [
            'name' => 'Rasoio',
            'billing' => 'subscription',
            'access' => 'required',
        ],
        'plat' => [
            'name' => 'Plat',
            'billing' => 'subscription',
            'access' => 'required',
        ],
        'inkap' => [
            'name' => 'Inkap',
            'billing' => 'subscription',
            'access' => 'optional',
        ],
        'nexus' => [
            'name' => 'Nexus',
            'billing' => 'subscription',
            'access' => 'required',
        ],
        'payflow' => [
            'name' => 'PayFlow',
            'billing' => 'subscription',
            'access' => 'required',
        ],
        'laora' => [
            'name' => 'Laora',
            'billing' => 'subscription',
            'access' => 'optional',
        ],
        'locaio' => [
            'name' => 'Locaio',
            'billing' => 'subscription',
            'access' => 'required',
        ],
        'kryvion' => [
            'name' => 'Kryvion',
            'billing' => 'subscription',
            'access' => 'required',
        ],

        // Produtos transacionais ou internos não recebem paywall de assinatura.
        'cutinapp' => [
            'name' => 'Cutinapp',
            'billing' => 'transactional',
            'access' => 'free',
            'revenue_source' => 'ticket_sales',
        ],
        'admin-center' => [
            'name' => 'Admin Center',
            'billing' => 'internal',
            'access' => 'free',
        ],
        'petertecnet' => [
            'name' => 'Peter Tecnet',
            'billing' => 'public',
            'access' => 'free',
        ],
    ],
];
