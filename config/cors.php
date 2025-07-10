<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CORS Configuration
    |--------------------------------------------------------------------------
    |
    | Define as configurações para requisições cross-origin.
    | Esta configuração permite que qualquer origem acesse a API,
    | sem suporte a cookies ou credenciais (suporte a sessões).
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'], // PERMITE QUALQUER ORIGEM

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'], // PERMITE TODOS OS HEADERS

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false, // NÃO PERMITE USO DE COOKIES/CREDENCIAIS (OBRIGATÓRIO COM '*')
];
