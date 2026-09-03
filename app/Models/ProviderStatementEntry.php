<?php

namespace App\Models;

use Carbon\CarbonImmutable;
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

    public function setOccurredAtAttribute(mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['occurred_at'] = null;
            return;
        }

        $this->attributes['occurred_at'] = CarbonImmutable::parse($value)
            ->setTimezone((string) config('app.timezone', 'UTC'))
            ->format('Y-m-d H:i:s');
    }

    public function setRawAttribute(mixed $value): void
    {
        $data = is_array($value) ? $value : (json_decode((string) $value, true) ?: []);
        $description = strtolower((string) ($this->attributes['description'] ?? ''));

        if ($description !== 'payment' && ! array_key_exists('_matched_payment_id', $data)) {
            $data['_matched_payment_id'] = 'not_applicable';
        }

        $this->attributes['raw'] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Provider statement entries are immutable. Import a new statement instead.'));
        static::deleting(fn () => throw new LogicException('Provider statement entries are immutable and cannot be deleted.'));
    }
}
