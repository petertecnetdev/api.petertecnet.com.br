<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use HasFactory;

    /**
     * Permite mass‑assignment nestes campos
     */
    protected $fillable = [
        'app_id',
        'entity_name',
        'entity_id',
        'scheduled_at',
        'expected_end_time',
        'service_ids',
        'provider_id',
        'client_id',
        'registered_by',
        'status',
        'location',
        'duration',
        'notes',
        'payment_status',
        'appointment_type',
        'attendance_status',
        'client_confirmation',
    ];

    /**
     * Casts
     */
    protected $casts = [
        'scheduled_at'      => 'datetime',
        'expected_end_time' => 'datetime',
        'service_ids'       => 'array',
    ];

    /**
     * Quem presta o serviço (User genérico)
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    /**
     * Perfil de Barber (opcional)
     */
    public function barber(): HasOne
    {
        return $this->hasOne(Barber::class, 'user_id', 'provider_id');
    }

    /**
     * Acesso “virtual” ao perfil ativo (Barber, Doctor, Dentist…)
     */
    public function getProviderProfileAttribute()
    {
        return $this->barber
             ?? $this->doctor
             ?? $this->dentist;
    }

    /**
     * Entidade polimórfica (Barbershop, Hospital…)
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Pluck dos nomes dos serviços
     */
    public function getServiceNamesAttribute()
    {
        if (! is_array($this->service_ids)) {
            return collect();
        }
        return Item::whereIn('id', $this->service_ids)
                   ->where('category', 'Serviços')
                   ->pluck('name');
    }
}
