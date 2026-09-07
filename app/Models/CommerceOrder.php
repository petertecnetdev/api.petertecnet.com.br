<?php

namespace App\Models;

use App\Services\EventAudienceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CommerceOrder extends Model
{
    protected $table = 'commerce_orders';

    protected $fillable = ['app_id','public_id','event_id','production_id','user_id','status','currency','subtotal','platform_fee','processor_fee','discount_amount','total','producer_net','payment_method','expires_at','paid_at','cancelled_at','recovery_started_at','metadata'];
    protected $casts = ['app_id'=>'integer','subtotal'=>'decimal:2','platform_fee'=>'decimal:2','processor_fee'=>'decimal:2','discount_amount'=>'decimal:2','total'=>'decimal:2','producer_net'=>'decimal:2','expires_at'=>'datetime','paid_at'=>'datetime','cancelled_at'=>'datetime','recovery_started_at'=>'datetime','metadata'=>'array'];

    protected static function booted(): void
    {
        static::creating(function (CommerceOrder $order) {
            $campaignUuid = trim((string) request()->input('campaign_uuid', ''));
            if ($campaignUuid === '') return;

            $campaign = PromotionCampaign::query()
                ->where('uuid', $campaignUuid)
                ->where('app_id', $order->app_id)
                ->where('event_id', $order->event_id)
                ->where('status', 'published')
                ->where(function ($query) {
                    $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                })
                ->first();

            if (! $campaign) return;
            $metadata = is_array($order->metadata) ? $order->metadata : [];
            $metadata['campaign_uuid'] = $campaign->uuid;
            $metadata['campaign_id'] = $campaign->id;
            $order->metadata = $metadata;
        });

        static::updating(function (CommerceOrder $order) {
            if (! $order->isDirty('subtotal') || (float) $order->discount_amount > 0) return;

            $rewardCode = trim((string) request()->input('reward_code', ''));
            if ($rewardCode === '') return;

            $campaignId = (int) data_get($order->metadata, 'campaign_id', 0);
            if ($campaignId <= 0) {
                throw ValidationException::withMessages(['reward_code' => ['O benefício precisa estar vinculado à campanha ativa que originou esta compra.']]);
            }

            $reward = PromotionCampaignReward::query()
                ->where('campaign_id', $campaignId)
                ->where('user_id', $order->user_id)
                ->where('code', $rewardCode)
                ->lockForUpdate()
                ->first();

            if (! $reward || ! in_array($reward->kind, ['coupon','discount'], true)) {
                throw ValidationException::withMessages(['reward_code' => ['Este benefício não é válido para desconto nesta compra.']]);
            }
            if ($reward->expires_at && $reward->expires_at->isPast()) {
                throw ValidationException::withMessages(['reward_code' => ['Este benefício expirou.']]);
            }

            if ($reward->status === 'reserved') {
                $reservedOrderId = (int) data_get($reward->metadata, 'reserved_order_id', 0);
                if ($reservedOrderId > 0 && $reservedOrderId !== (int) $order->id) {
                    $reservedOrder = self::query()->lockForUpdate()->find($reservedOrderId);
                    $stillActive = $reservedOrder
                        && $reservedOrder->status === 'pending'
                        && (! $reservedOrder->expires_at || $reservedOrder->expires_at->isFuture());
                    if ($stillActive) {
                        throw ValidationException::withMessages(['reward_code' => ['Este benefício já está reservado em outra compra em andamento.']]);
                    }
                }
            } elseif ($reward->status !== 'issued') {
                throw ValidationException::withMessages(['reward_code' => ['Este benefício já foi utilizado ou não está mais disponível.']]);
            }

            $subtotal = round((float) $order->subtotal, 2);
            $discount = round(max(0, (float) $reward->value), 2);
            if ($subtotal <= 0 || $discount <= 0) {
                throw ValidationException::withMessages(['reward_code' => ['O benefício não possui um valor de desconto válido.']]);
            }
            if ($discount >= $subtotal) {
                throw ValidationException::withMessages(['reward_code' => ['Use este benefício em uma compra com valor superior ao desconto, para que o pagamento continue válido.']]);
            }

            $total = round($subtotal - $discount, 2);
            $platformRate = $subtotal > 0 ? ((float) $order->platform_fee / $subtotal) : 0.0;
            $platformFee = round($total * max(0, $platformRate), 2);
            $producerNet = round(max(0, $total - $platformFee), 2);

            $metadata = is_array($order->metadata) ? $order->metadata : [];
            $metadata['campaign_reward_id'] = $reward->id;
            $metadata['campaign_reward_code'] = $reward->code;
            $metadata['campaign_reward_kind'] = $reward->kind;
            $metadata['campaign_reward_discount'] = $discount;
            $order->discount_amount = $discount;
            $order->total = $total;
            $order->platform_fee = $platformFee;
            $order->producer_net = $producerNet;
            $order->metadata = $metadata;

            $rewardMetadata = is_array($reward->metadata) ? $reward->metadata : [];
            $rewardMetadata['reserved_order_id'] = (int) $order->id;
            $rewardMetadata['reserved_order_public_id'] = $order->public_id;
            $rewardMetadata['reserved_at'] = now()->toIso8601String();
            $reward->status = 'reserved';
            $reward->metadata = $rewardMetadata;
            $reward->save();
        });

        static::updated(function (CommerceOrder $order) {
            if ($order->wasChanged('status') && $order->status === 'paid') {
                app(EventAudienceService::class)->confirmPaidOrder((int) $order->id);
            }
        });
    }

    public function application(){return $this->belongsTo(Application::class,'app_id');}
    public function event(){return $this->belongsTo(Event::class);}
    public function production(){return $this->belongsTo(Production::class);}
    public function user(){return $this->belongsTo(User::class);}
    public function items(){return $this->hasMany(CommerceOrderItem::class,'order_id');}
    public function payments(){return $this->hasMany(CommercePayment::class,'order_id');}
}
