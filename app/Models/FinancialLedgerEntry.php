<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FinancialLedgerEntry extends Model
{
    protected $fillable = [
        'public_id', 'payment_id', 'app_id', 'app_slug', 'establishment_id',
        'production_id', 'provider', 'provider_payment_id', 'event_type', 'currency',
        'gross_amount', 'platform_amount', 'provider_amount', 'seller_amount',
        'occurred_at', 'source_type', 'source_reference', 'idempotency_key', 'metadata',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'platform_amount' => 'decimal:2',
        'provider_amount' => 'decimal:2',
        'seller_amount' => 'decimal:2',
        'occurred_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Financial ledger entries are immutable. Create a compensating entry instead.'));
        static::deleting(fn () => throw new LogicException('Financial ledger entries are immutable and cannot be deleted.'));
    }
}
