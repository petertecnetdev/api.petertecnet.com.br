<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EventItem extends Model
{
    protected $table = 'event_items';
    protected $fillable = ['app_id','event_id','source_item_id','name','description','price','quantity','promotion_enabled','promotion_price','is_active'];
    protected $casts = ['app_id'=>'integer','source_item_id'=>'integer','price'=>'decimal:2','quantity'=>'integer','promotion_enabled'=>'boolean','promotion_price'=>'decimal:2','is_active'=>'boolean'];

    protected static function booted(): void
    {
        static::creating(function (EventItem $eventItem) {
            if (app()->runningInConsole()) return;

            $routeEventId = (int) request()->route('eventId');
            if ($routeEventId <= 0 || ! request()->isMethod('post')) return;

            $sourceItemId = (int) request()->input('source_item_id');
            if ($sourceItemId <= 0) {
                throw ValidationException::withMessages([
                    'source_item_id' => ['Selecione um item já cadastrado no catálogo da produção.'],
                ]);
            }

            $event = Event::query()
                ->whereKey($eventItem->event_id ?: $routeEventId)
                ->where('app_id', $eventItem->app_id)
                ->first();

            if (! $event) {
                throw ValidationException::withMessages([
                    'source_item_id' => ['O evento informado não está disponível neste aplicativo.'],
                ]);
            }

            $sourceItem = Item::query()
                ->whereKey($sourceItemId)
                ->where('entity_name', 'establishment')
                ->where('entity_id', $event->production_id)
                ->where('status', true)
                ->first();

            if (! $sourceItem) {
                throw ValidationException::withMessages([
                    'source_item_id' => ['O item selecionado não pertence ao catálogo desta produção ou está inativo.'],
                ]);
            }

            $eventItem->name = $sourceItem->name;
            $eventItem->description = $sourceItem->description;
        });
    }

    public function application(){return $this->belongsTo(Application::class,'app_id');}
    public function event(){return $this->belongsTo(Event::class);}
}