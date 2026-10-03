<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EcosystemPayment extends Model
{
    protected $fillable = [
        'public_id', 'app_id', 'app_slug', 'provider', 'provider_payment_id',
        'source_type', 'source_reference', 'source_id', 'user_id', 'production_id',
        'establishment_id', 'currency', 'method', 'status', 'gross_amount',
        'platform_fee', 'provider_fee', 'seller_net', 'metadata', 'paid_at',
        'refunded_at', 'failed_at', 'expires_at', 'available_at', 'reconciled_at',
        'reconciliation_status', 'reconciliation_message', 'settled_at',
        'settlement_status', 'settlement_reference', 'settlement_net_amount',
    ];

    protected $casts = [
        'metadata' => 'array',
        'gross_amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'provider_fee' => 'decimal:2',
        'seller_net' => 'decimal:2',
        'settlement_net_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'failed_at' => 'datetime',
        'expires_at' => 'datetime',
        'available_at' => 'datetime',
        'settled_at' => 'datetime',
        'reconciled_at' => 'datetime',
    ];
}
