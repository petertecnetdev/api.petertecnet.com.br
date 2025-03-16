<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Se você não precisa enviar cookies ou autenticação via credenciais, defina como false.
    // Usar '*' com suporte a credenciais (true) não é permitido.
    'supports_credentials' => false,

    'allowed_origins' => ['*'],

    // Se 'allowed_origins' for '*', não é necessário definir padrões de origem.
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,
];
