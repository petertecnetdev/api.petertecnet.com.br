<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProviderStatementEntry extends Model
{
    protected $fillable = [
        'report_id', 'provider', 'provider_source_id', 'external_reference', 'record_type',
        'description', 'currency', 'gross_amount', 'net_credit_amount', 'net_debit_amount',
        'provider_fee_amount', 'seller_amount', 'balance_amount', 'occurred_at',
        'bank_account_reference', 'fingerprint', 'raw',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'net_credit_amount' => 'decimal:2',
        'net_debit_amount' => 'decimal:2',
        'provider_fee_amount' => 'decimal:2',
        'seller_amount' => 'decimal:2',
        'balance_amount' => 'decimal:2',
        'occurred_at' => 'datetime',
        'raw' => 'array',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(ProviderStatementReport::class, 'report_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Provider statement entries are immutable. Import a new statement instead.'));
        static::deleting(fn () => throw new LogicException('Provider statement entries are immutable and cannot be deleted.'));
    }
}
