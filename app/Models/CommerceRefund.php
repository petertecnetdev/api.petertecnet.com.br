<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommerceRefund extends Model
{
    protected $fillable = [
        'app_id',
        'order_id',
        'payment_id',
        'requested_by_user_id',
        'source_type',
        'source_id',
        'status',
        'amount',
        'currency',
        'provider',
        'provider_refund_id',
        'idempotency_key',
        'reason',
        'provider_payload',
        'requested_at',
        'processed_at',
        'failed_at',
        'failure_message',
    ];

    protected $casts = [
        'app_id' => 'integer',
        'order_id' => 'integer',
        'payment_id' => 'integer',
        'requested_by_user_id' => 'integer',
        'source_id' => 'integer',
        'amount' => 'decimal:2',
        'provider_payload' => 'array',
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected $hidden = ['provider_payload'];

    public function application(){ return $this->belongsTo(Application::class, 'app_id'); }
    public function order(){ return $this->belongsTo(CommerceOrder::class, 'order_id'); }
    public function payment(){ return $this->belongsTo(CommercePayment::class, 'payment_id'); }
    public function requestedBy(){ return $this->belongsTo(User::class, 'requested_by_user_id'); }
}
