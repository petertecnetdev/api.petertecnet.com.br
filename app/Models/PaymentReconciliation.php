<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReconciliation extends Model
{
    protected $fillable = [
        'payment_id', 'provider', 'provider_payment_id', 'local_status', 'remote_status',
        'local_amount', 'remote_amount', 'local_provider_fee', 'remote_provider_fee',
        'matched', 'discrepancy_code', 'details', 'checked_at',
    ];

    protected $casts = [
        'local_amount' => 'decimal:2',
        'remote_amount' => 'decimal:2',
        'local_provider_fee' => 'decimal:2',
        'remote_provider_fee' => 'decimal:2',
        'matched' => 'boolean',
        'details' => 'array',
        'checked_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(EcosystemPayment::class, 'payment_id');
    }
}
