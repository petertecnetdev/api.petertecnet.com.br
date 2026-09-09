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

    protected $fillable = [
        'app_id','app_slug','event_id','name','type','price','limit_date','sales_cutoff_mode',
        'sales_cutoff_offset_minutes','ticket_type','quantity','max_per_user','description',
    ];
    protected $casts = [
        'price'=>'decimal:2',
        'quantity'=>'integer',
        'max_per_user'=>'integer',
        'limit_date'=>'datetime',
        'sales_cutoff_offset_minutes'=>'integer',
    ];
    protected $appends = ['remaining','available','expired'];
    protected $table = 'tickets';

    protected static function booted(): void
    {
        static::created(fn (Ticket $ticket) => app(EventAudienceService::class)->notifyNewTicket($ticket));
        static::saving(function (Ticket $ticket) {
            if (! $ticket->limit_date || ! $ticket->event_id) return;
            if ($ticket->exists && ! $ticket->isDirty(['limit_date', 'event_id'])) return;

            $event = Event::query()->find($ticket->event_id);
            if (! $event) return;

            $timezone = config('app.timezone', 'America/Sao_Paulo');
            $limit = Carbon::parse($ticket->limit_date, $timezone);
            if ($event->end_date && $limit->gt(Carbon::parse($event->end_date, $timezone))) {
                $eventEnd = Carbon::parse($event->end_date, $timezone)->format('d/m/Y \\à\\s H:i');
                throw ValidationException::withMessages([
                    'limit_date' => ["O encerramento das vendas não pode ultrapassar o término do evento ({$eventEnd})."],
                ]);
            }
        });
    }

    public function getExpiredAttribute(): bool
    {
        if ($this->limit_date && now()->greaterThanOrEqualTo($this->limit_date)) return true;
        if (! $this->event_id) return false;

        $event = $this->relationLoaded('event')
            ? $this->getRelation('event')
            : Event::query()->find($this->event_id);

        return (bool) ($event && $event->hasEnded());
    }

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
