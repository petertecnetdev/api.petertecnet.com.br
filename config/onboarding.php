<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application domain mapping
    |--------------------------------------------------------------------------
    |
    | Product identity belongs in configuration, not in runtime services. The
    | onboarding service consumes only generic domain names and the current
    | application's display name, keeping the workflow reusable across apps.
    */
    'application_domains' => [
        ['contains' => ['cutinapp'], 'domain' => 'events'],
        ['contains' => ['nexus'], 'domain' => 'catalog'],
        ['contains' => ['plat'], 'domain' => 'restaurant'],
        ['contains' => ['rasoio'], 'domain' => 'scheduling'],
        ['contains' => ['payflow'], 'domain' => 'crm'],
        ['contains' => ['locaio'], 'domain' => 'real_estate'],
        ['contains' => ['kryvion'], 'domain' => 'market'],
    ],
];
