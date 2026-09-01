<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EventPass extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'cutinapp_order_item_id',
        'event_id',
        'user_id',
        'holder_name',
        'holder_email',
        'token',
        'status',
        'checked_in_at',
        'checked_in_by',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
    ];

    protected $hidden = [];

    protected static function booted(): void
    {
        static::saving(function (EventPass $pass) {
            if (! $pass->isDirty('checked_in_at') || ! $pass->checked_in_at) {
                return;
            }

            $previousStatus = (string) $pass->getOriginal('status');
            if (in_array($previousStatus, ['cancelled', 'refunded', 'charged_back'], true)) {
                throw ValidationException::withMessages([
                    'token' => ['Este ingresso foi cancelado ou teve o pagamento revertido e não pode ser utilizado.'],
                ]);
            }

            $event = $pass->relationLoaded('event') ? $pass->event : $pass->event()->first();
            if (! $event || $event->app_slug !== 'cutinapp') {
                return;
            }

            $now = now(config('app.timezone'));
            $start = $event->start_date?->copy()->timezone(config('app.timezone'));
            $end = $event->end_date?->copy()->timezone(config('app.timezone'));

            if ($start && $now->lt($start)) {
                throw ValidationException::withMessages([
                    'token' => ['Este ingresso ainda não pode ser utilizado. A entrada será liberada no horário de início do evento.'],
                ]);
            }

            if ($end && $now->gt($end)) {
                throw ValidationException::withMessages([
                    'token' => ['Este ingresso não pode mais ser utilizado porque o evento já terminou.'],
                ]);
            }
        });
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(CutinappOrderItem::class, 'cutinapp_order_item_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function checkedInBy()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }
}
