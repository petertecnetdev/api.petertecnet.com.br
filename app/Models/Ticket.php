<?php

namespace App\Models;

use App\Services\EventAudienceService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = ['app_id','app_slug','event_id','name','type','price','limit_date','ticket_type','quantity','description'];
    protected $casts = ['price'=>'decimal:2','quantity'=>'integer','limit_date'=>'datetime'];
    protected $appends = ['remaining','available','expired'];
    protected $table = 'tickets';

    protected static function booted(): void
    {
        static::created(fn (Ticket $ticket) => app(EventAudienceService::class)->notifyNewTicket($ticket));
        static::saving(function (Ticket $ticket) {
            if (! $ticket->limit_date || ! $ticket->event_id) return;
            $event = Event::query()->find($ticket->event_id); if (! $event) return;
            $timezone=config('app.timezone'); $limit=Carbon::parse($ticket->limit_date,$timezone); $minimum=Carbon::now($timezone)->addHour();
            if($limit->lt($minimum)) throw ValidationException::withMessages(['limit_date'=>['O prazo de retirada precisa ser de pelo menos 1 hora após o horário atual.']]);
            if($event->start_date && $limit->gt($event->start_date)){
                $eventStart=Carbon::parse($event->start_date,$timezone)->format('d/m/Y \à\s H:i');
                throw ValidationException::withMessages(['limit_date'=>["O prazo de retirada não pode ultrapassar o início do evento ({$eventStart})."]]);
            }
        });
    }

    public function getExpiredAttribute(): bool { return (bool)($this->limit_date && now()->greaterThan($this->limit_date)); }
    public function getRemainingAttribute(): int
    {
        $total=max(0,(int)$this->quantity); if(!$this->exists) return $total;
        $issued=EventPass::query()->where('ticket_id',$this->id)->whereNotIn('status',['cancelled','refunded','charged_back'])->count();
        $reserved=(int)DB::table('inventory_reservations')->where('ticket_id',$this->id)->whereNull('released_at')->where('expires_at','>',now())->sum('quantity');
        return max(0,$total-$issued-$reserved);
    }
    public function getAvailableAttribute(): bool { return !$this->expired && $this->remaining>0; }
    public function application(){return $this->belongsTo(Application::class,'app_id');} public function event(){return $this->belongsTo(Event::class);} public function passes(){return $this->hasMany(EventPass::class,'ticket_id');}
}
