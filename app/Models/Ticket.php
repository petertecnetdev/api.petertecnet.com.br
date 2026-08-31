<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_id', 'app_slug', 'event_id', 'name', 'type', 'price', 'limit_date',
        'ticket_type', 'quantity', 'description',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'limit_date' => 'datetime',
    ];

    protected $table = 'tickets';
    protected $primaryKey = 'id';

    protected static function booted(): void
    {
        static::saving(function (Ticket $ticket) {
            if ($ticket->app_slug !== 'cutinapp' || ! $ticket->limit_date || ! $ticket->event_id) {
                return;
            }

            $event = Event::query()->find($ticket->event_id);
            if (! $event || $event->app_slug !== 'cutinapp') {
                return;
            }

            $limit = Carbon::parse($ticket->limit_date, config('app.timezone'));
            if ($limit->lte(now())) {
                throw ValidationException::withMessages([
                    'limit_date' => ['O prazo de retirada da cortesia precisa ficar no futuro.'],
                ]);
            }

            if ($event->start_date && $limit->gt($event->start_date)) {
                throw ValidationException::withMessages([
                    'limit_date' => ['O prazo de retirada da cortesia não pode ultrapassar o início do evento.'],
                ]);
            }
        });
    }

    public function application()
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function passes()
    {
        return $this->hasMany(EventPass::class, 'ticket_id');
    }
}
