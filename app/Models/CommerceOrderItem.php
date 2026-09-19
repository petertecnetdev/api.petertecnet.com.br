<?php

namespace App\Models;

use App\Domain\Commerce\Support\CommerceOrderItemTotals;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CommerceOrderItem extends Model
{
    protected $table = 'commerce_order_items';
    protected $fillable = ['app_id','order_id','type','ticket_id','event_item_id','name','unit_price','quantity','subtotal','metadata'];
    protected $casts = ['app_id'=>'integer','unit_price'=>'decimal:2','subtotal'=>'decimal:2','quantity'=>'integer','metadata'=>'array'];

    protected static function booted(): void
    {
        static::saving(function (CommerceOrderItem $item): void {
            $normalized = CommerceOrderItemTotals::normalize((float) $item->unit_price, (int) $item->quantity);
            $item->unit_price = $normalized['unit_price'];
            $item->quantity = $normalized['quantity'];
            $item->subtotal = $normalized['subtotal'];

            if (! $item->order_id || ! $item->app_id) {
                return;
            }

            $orderAppId = CommerceOrder::query()
                ->whereKey($item->order_id)
                ->value('app_id');

            if ($orderAppId !== null && (int) $orderAppId !== (int) $item->app_id) {
                throw new InvalidArgumentException('Order item must belong to the same application as its order.');
            }
        });
    }

    public function application(){return $this->belongsTo(Application::class,'app_id');}
    public function order(){return $this->belongsTo(CommerceOrder::class,'order_id');}
    public function ticket(){return $this->belongsTo(Ticket::class);}
    public function eventItem(){return $this->belongsTo(EventItem::class,'event_item_id');}
}
