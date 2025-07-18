<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        // ...
    ];

    protected $casts = [
        'scheduled_at'      => 'datetime',
        'expected_end_time' => 'datetime',
        'service_ids'       => 'array',
    ];

    /**
     * Quem presta o serviço (sempre um User)
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'provider_id');
    }

    /**
     * Perfil de barbeiro (se existir) — o slug vive aqui
     */
    public function barber(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Barber::class, 'provider_id', 'user_id');
    }

    /**
     * Polimórfico para Barbershop, Hospital, etc.
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Pega os nomes dos serviços
     */
    public function getServiceNamesAttribute()
    {
        if (! is_array($this->service_ids)) {
            return collect();
        }
        return \App\Models\Item::whereIn('id', $this->service_ids)
            ->where('category', 'Serviços')
            ->pluck('name');
    }
}
