<?php

namespace App\Domain\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookReceipt extends Model
{
    protected $table = 'webhook_receipts';

    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'payload_hash',
        'payload',
        'status',
        'attempts',
        'received_at',
        'processed_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }
}
