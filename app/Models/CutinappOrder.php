<?php

namespace App\Models;

use App\Services\CutinappEventAudienceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CutinappOrder extends Model
{
    protected $table = 'cutinapp_orders';

    protected $fillable = ['public_id','event_id','production_id','user_id','status','currency','subtotal','platform_fee','processor_fee','discount_amount','total','producer_net','payment_method','expires_at','paid_at','cancelled_at','metadata'];

    protected $casts = [
        'subtotal'=>'decimal:2','platform_fee'=>'decimal:2','processor_fee'=>'decimal:2','discount_amount'=>'decimal:2','total'=>'decimal:2','producer_net'=>'decimal:2',
        'expires_at'=>'datetime','paid_at'=>'datetime','cancelled_at'=>'datetime','metadata'=>'array',
    ];

    protected static function booted(): void
    {
        static::updated(function (CutinappOrder $order) {
            if (! $order->wasChanged('status') || $order->status !== 'paid') return;

            $orderId = (int) $order->id;
            DB::afterCommit(function () use ($orderId) {
                app(CutinappEventAudienceService::class)->confirmPaidOrder($orderId);
            });
        });
    }

    public function event(){ return $this->belongsTo(Event::class); }
    public function production(){ return $this->belongsTo(Production::class); }
    public function user(){ return $this->belongsTo(User::class); }
    public function items(){ return $this->hasMany(CutinappOrderItem::class, 'order_id'); }
    public function payments(){ return $this->hasMany(CutinappPayment::class, 'order_id'); }
}
