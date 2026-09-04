<?php

return [
    'snapshot_relationship_limit' => 50,

    // Relationships can grant narrowly-scoped capabilities for the resource they belong to.
    // This is intentionally independent from application names.
    'relationship_permissions' => [
        'tenant' => [
            'agreement' => ['agreements.view', 'agreements.sign', 'payments.view'],
            'lease' => ['agreements.view', 'agreements.sign', 'payments.view'],
            'real_estate_lease' => ['agreements.view', 'agreements.sign', 'payments.view'],
        ],
        'landlord' => [
            'agreement' => ['agreements.view', 'agreements.manage', 'agreements.sign', 'payments.view', 'payments.manage'],
            'lease' => ['agreements.view', 'agreements.manage', 'agreements.sign', 'payments.view', 'payments.manage'],
            'real_estate_lease' => ['agreements.view', 'agreements.manage', 'agreements.sign', 'payments.view', 'payments.manage'],
        ],
        'guarantor' => [
            'agreement' => ['agreements.view', 'agreements.sign'],
            'lease' => ['agreements.view', 'agreements.sign'],
            'real_estate_lease' => ['agreements.view', 'agreements.sign'],
        ],
        'representative' => [
            'agreement' => ['agreements.view', 'agreements.manage', 'agreements.sign'],
            'lease' => ['agreements.view', 'agreements.manage', 'agreements.sign'],
            'real_estate_lease' => ['agreements.view', 'agreements.manage', 'agreements.sign'],
        ],
    ],
];
