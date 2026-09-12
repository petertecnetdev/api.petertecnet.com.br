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

    /*
    |--------------------------------------------------------------------------
    | Zero-cost automated recovery
    |--------------------------------------------------------------------------
    |
    | A single in-app reminder can be created for a still-payable PIX checkout.
    | This path explicitly suppresses e-mail and external providers, so enabling
    | it does not authorize paid messaging. recovery_started_at prevents repeats.
    |
    | A small deterministic holdout measures how many pending checkouts would have
    | converted without the reminder, so profitability can be based on incremental
    | revenue instead of attributing every later payment to the recovery action.
    |
    | Timing variants are deterministically assigned per order. Control orders are
    | only marked after their assigned delay too, keeping treatment and holdout
    | cohorts comparable without increasing message volume or external spend.
    |
    */
    'automated_in_app' => [
        'enabled' => env('CHECKOUT_RECOVERY_IN_APP_ENABLED', true),
        'delay_minutes' => env('CHECKOUT_RECOVERY_IN_APP_DELAY_MINUTES', 5),
        'timing_experiment_enabled' => env('CHECKOUT_RECOVERY_TIMING_EXPERIMENT_ENABLED', true),
        'timing_experiment_name' => env('CHECKOUT_RECOVERY_TIMING_EXPERIMENT_NAME', 'pix_in_app_timing_v1'),
        'timing_delays_minutes' => env('CHECKOUT_RECOVERY_TIMING_DELAYS_MINUTES', '5,15,30,60'),
        'minimum_remaining_minutes' => env('CHECKOUT_RECOVERY_IN_APP_MIN_REMAINING_MINUTES', 5),
        'batch_limit' => env('CHECKOUT_RECOVERY_IN_APP_BATCH_LIMIT', 100),
        'control_group_percent' => env('CHECKOUT_RECOVERY_IN_APP_CONTROL_PERCENT', 10),

        // Internal routes keep the user inside the SPA and remove a navigation step.
        // The source marker lets ecosystem telemetry attribute the landing to the
        // zero-cost reminder without exposing user data or changing checkout state.
        'deep_link_paths_by_host' => [
            'cutinapp.petertecnet.com.br' => '/purchases/{public_id}?recovery_source=in_app',
            'plat.petertecnet.com.br' => '/my-orders/{id}?recovery_source=in_app',
        ],
    ],
];
