<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercePayment extends Model
{
    protected $table = 'commerce_payments';
    protected $fillable = ['app_id','order_id','provider','method','status','provider_payment_id','provider_txid','idempotency_key','amount','provider_fee','qr_code','qr_code_image','ticket_url','provider_payload','paid_at','refunded_at','failed_at'];
    protected $casts = ['app_id'=>'integer','amount'=>'decimal:2','provider_fee'=>'decimal:2','provider_payload'=>'array','paid_at'=>'datetime','refunded_at'=>'datetime','failed_at'=>'datetime'];

    protected static function booted(): void
    {
        static::updated(function (CommercePayment $payment): void {
            if (! $payment->wasChanged('status') || ! in_array($payment->status, ['rejected', 'cancelled'], true)) return;

            $alreadyRecorded = Interaction::query()
                ->where('app_id', $payment->app_id)
                ->where('entity_type', 'CommercePayment')
                ->where('entity_id', $payment->id)
                ->where('interaction_type', 'payment_failed')
                ->exists();

            if ($alreadyRecorded) return;

            $order = $payment->order()->with('user')->first();

            Interaction::register('payment_failed', $payment, $order?->user, [
                'source_channel' => 'payment_provider',
                'app_id' => $payment->app_id,
                'order_id' => $payment->order_id,
                'order_public_id' => $order?->public_id,
                'provider' => $payment->provider,
                'provider_payment_id' => $payment->provider_payment_id,
                'payment_method' => $payment->method,
                'payment_status' => $payment->status,
                'amount' => (float) $payment->amount,
                'production_id' => $order?->production_id,
            ], 'Pagamento não aprovado');
        });
    }

    public function application(){return $this->belongsTo(Application::class,'app_id');}
    public function order(){return $this->belongsTo(CommerceOrder::class,'order_id');}
}
