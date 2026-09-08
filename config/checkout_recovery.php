<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Recovery action economics
    |--------------------------------------------------------------------------
    |
    | Configure the real marginal cost of one recovery attempt. Leaving a value
    | unset preserves the current ranking and avoids inventing operational cost.
    | Payment-method overrides are optional and fall back to the default cost.
    |
    */
    'default_attempt_cost' => env('CHECKOUT_RECOVERY_ATTEMPT_COST'),

    'attempt_cost_by_payment_method' => array_filter([
        'pix' => env('CHECKOUT_RECOVERY_ATTEMPT_COST_PIX'),
        'card' => env('CHECKOUT_RECOVERY_ATTEMPT_COST_CARD'),
        'credit_card' => env('CHECKOUT_RECOVERY_ATTEMPT_COST_CREDIT_CARD'),
        'boleto' => env('CHECKOUT_RECOVERY_ATTEMPT_COST_BOLETO'),
    ], static fn (mixed $value): bool => $value !== null && $value !== ''),
];
