<?php

return [
    'quality' => [
        'publish_threshold' => (int) env('CATALOG_PUBLISH_QUALITY_THRESHOLD', 70),
        'excellent_threshold' => 90,
    ],

    // Profiles are domain concepts, not application-specific rules. New categories
    // can reuse these profiles or define additional profiles without migrations.
    'specification_profiles' => [
        'default_product' => [
            'required' => [],
            'recommended' => ['brand'],
        ],
        'paint' => [
            'required' => ['package_quantity', 'package_unit'],
            'recommended' => ['color', 'finish', 'brand'],
        ],
        'sealant' => [
            'required' => ['package_quantity', 'package_unit'],
            'recommended' => ['color', 'application', 'brand'],
        ],
        'fastener' => [
            'required' => ['diameter', 'length'],
            'recommended' => ['material', 'brand'],
        ],
        'roofing' => [
            'required' => ['length', 'width'],
            'recommended' => ['thickness', 'material', 'brand'],
        ],
        'sheet_or_covering' => [
            'required' => ['length', 'width'],
            'recommended' => ['material', 'brand'],
        ],
    ],

    'profile_keywords' => [
        'paint' => ['tinta', 'verniz', 'esmalte', 'primer', 'selador'],
        'sealant' => ['silicone', 'selante', 'adesivo', 'cola'],
        'fastener' => ['parafuso', 'porca', 'arruela', 'bucha', 'prego'],
        'roofing' => ['telha', 'cumeeira'],
        'sheet_or_covering' => ['manta', 'lona', 'cobre tudo', 'cobretudo'],
    ],

    'units' => [
        'mm', 'cm', 'm', 'ml', 'l', 'g', 'kg', 'un', 'pct', 'cx', 'rolo', 'par',
    ],
];
