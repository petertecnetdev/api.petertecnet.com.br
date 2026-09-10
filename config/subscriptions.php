<?php

return [
    'currency' => 'BRL',
    'billing_interval' => 'month',
    'billing_interval_count' => 1,

    /*
    |--------------------------------------------------------------------------
    | Peter Tecnet subscription catalog
    |--------------------------------------------------------------------------
    |
    | Prices are stored in cents to avoid floating point errors. Frontends must
    | consume the public subscription-plan endpoint instead of hardcoding prices.
    |
    */
    'applications' => [
        'rasoio' => [
            'name' => 'Rasoio',
            'subscription_enabled' => true,
            'plans' => [
                [
                    'code' => 'essencial',
                    'name' => 'Essencial',
                    'price_cents' => 3990,
                    'recommended' => false,
                    'features' => ['Agenda', 'Clientes', 'Serviços', '1 estabelecimento'],
                    'entitlements' => [
                        'application_access' => true,
                        'establishments.max' => 1,
                    ],
                ],
                [
                    'code' => 'pro',
                    'name' => 'Pro',
                    'price_cents' => 6990,
                    'recommended' => true,
                    'features' => ['Tudo do Essencial', 'Colaboradores', 'Relatórios', 'Automações', 'Recursos avançados'],
                    'entitlements' => [
                        'application_access' => true,
                        'establishments.max' => 1,
                        'staff.management' => true,
                        'analytics.reports' => true,
                        'automation.rules' => true,
                        'features.advanced' => true,
                    ],
                ],
                [
                    'code' => 'business',
                    'name' => 'Business',
                    'price_cents' => 11990,
                    'recommended' => false,
                    'features' => ['Tudo do Pro', 'Múltiplas unidades', 'Gestão avançada', 'Recursos premium', 'Integração com Nexus'],
                    'entitlements' => [
                        'application_access' => true,
                        'establishments.max' => -1,
                        'staff.management' => true,
                        'analytics.reports' => true,
                        'automation.rules' => true,
                        'features.advanced' => true,
                        'establishments.multiple' => true,
                        'management.advanced' => true,
                        'features.premium' => true,
                        'integration.nexus' => true,
                    ],
                ],
            ],
        ],

        'plat' => [
            'name' => 'Plat',
            'subscription_enabled' => true,
            'plans' => [
                [
                    'code' => 'essencial',
                    'name' => 'Essencial',
                    'price_cents' => 4990,
                    'recommended' => false,
                    'features' => ['Cardápio', 'Produtos', 'QR Code', 'Pedidos', '1 estabelecimento'],
                ],
                [
                    'code' => 'pro',
                    'name' => 'Pro',
                    'price_cents' => 8990,
                    'recommended' => true,
                    'features' => ['Tudo do Essencial', 'Mesas', 'Comandas', 'Colaboradores', 'Relatórios', 'Automações'],
                ],
                [
                    'code' => 'business',
                    'name' => 'Business',
                    'price_cents' => 14990,
                    'recommended' => false,
                    'features' => ['Tudo do Pro', 'Múltiplas unidades', 'Gestão avançada', 'Recursos premium', 'Integração com Nexus'],
                ],
            ],
        ],

        'payflow' => [
            'name' => 'PayFlow',
            'subscription_enabled' => true,
            'plans' => [
                [
                    'code' => 'starter',
                    'name' => 'Starter',
                    'price_cents' => 3990,
                    'recommended' => false,
                    'features' => ['Clientes', 'Oportunidades', 'Propostas', 'Cobranças', 'Pipeline comercial'],
                ],
                [
                    'code' => 'pro',
                    'name' => 'Pro',
                    'price_cents' => 7990,
                    'recommended' => true,
                    'features' => ['Tudo do Starter', 'Follow-up', 'Automações', 'Relatórios', 'Recursos avançados'],
                ],
                [
                    'code' => 'business',
                    'name' => 'Business',
                    'price_cents' => 14990,
                    'recommended' => false,
                    'features' => ['Tudo do Pro', 'Equipe', 'Gestão avançada', 'Recursos premium', 'Integração com Nexus'],
                ],
            ],
        ],

        'kryvion' => [
            'name' => 'Kryvion',
            'subscription_enabled' => true,
            'freemium' => true,
            'plans' => [
                [
                    'code' => 'free',
                    'name' => 'Grátis',
                    'price_cents' => 0,
                    'recommended' => false,
                    'features' => ['Dashboard básico', 'Análises básicas', 'Radar com acesso limitado'],
                ],
                [
                    'code' => 'premium',
                    'name' => 'Premium',
                    'price_cents' => 2990,
                    'recommended' => true,
                    'features' => ['Sinais', 'Alertas em tempo real', 'Análises detalhadas', 'Portfólio'],
                ],
                [
                    'code' => 'pro',
                    'name' => 'Pro',
                    'price_cents' => 5990,
                    'recommended' => false,
                    'features' => ['Tudo do Premium', 'Simulador', 'Guardião de risco', 'Notificações avançadas'],
                ],
            ],
        ],

        'locaio' => [
            'name' => 'Locaio',
            'subscription_enabled' => true,
            'plans' => [
                [
                    'code' => 'essencial',
                    'name' => 'Essencial',
                    'price_cents' => 3990,
                    'recommended' => false,
                    'features' => ['Serviços', 'Agenda', 'Clientes', '1 estabelecimento'],
                ],
                [
                    'code' => 'pro',
                    'name' => 'Pro',
                    'price_cents' => 6990,
                    'recommended' => true,
                    'features' => ['Tudo do Essencial', 'Colaboradores', 'Automações', 'Relatórios', 'Recursos avançados'],
                ],
                [
                    'code' => 'business',
                    'name' => 'Business',
                    'price_cents' => 11990,
                    'recommended' => false,
                    'features' => ['Tudo do Pro', 'Múltiplas unidades', 'Gestão avançada', 'Recursos premium', 'Integração com Nexus'],
                ],
            ],
        ],

        // Cutinapp intentionally remains transaction-based, without a mandatory subscription.
        'cutinapp' => [
            'name' => 'Cutinapp',
            'subscription_enabled' => false,
            'monetization_model' => 'transaction_fee',
            'plans' => [],
        ],

        // Nexus is an ecosystem capability/bundle benefit, not a standalone mandatory subscription.
        'nexus' => [
            'name' => 'Nexus',
            'subscription_enabled' => false,
            'monetization_model' => 'bundle',
            'plans' => [],
        ],
    ],

    'bundles' => [
        [
            'code' => 'peter-tecnet-business',
            'name' => 'Peter Tecnet Business',
            'price_cents' => 12990,
            'currency' => 'BRL',
            'billing_interval' => 'month',
            'eligible_combinations' => [
                ['rasoio', 'payflow', 'nexus'],
                ['plat', 'payflow', 'nexus'],
            ],
        ],
    ],
];
