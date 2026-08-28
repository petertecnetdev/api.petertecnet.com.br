<?php

return [
    // Administrative bootstrap must be explicit in the environment.
    // Never promote an account based on a hard-coded repository value.
    'admin_email' => env('PETER_ADMIN_EMAIL'),
];
