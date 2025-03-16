<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

<<<<<<< HEAD
    // Se vocÃª nÃ£o precisa enviar cookies ou autenticaÃ§Ã£o via credenciais, defina como false.
    // Usar '*' com suporte a credenciais (true) nÃ£o Ã© permitido.
=======
    // Se você não precisa enviar cookies ou autenticação via credenciais, defina como false.
    // Usar '*' com suporte a credenciais (true) não é permitido.
>>>>>>> 823736e (corns on vps)
    'supports_credentials' => false,

    'allowed_origins' => ['*'],

<<<<<<< HEAD
    // Se 'allowed_origins' for '*', nÃ£o Ã© necessÃ¡rio definir padrÃµes de origem.
=======
    // Se 'allowed_origins' for '*', não é necessário definir padrões de origem.
>>>>>>> 823736e (corns on vps)
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,
];
