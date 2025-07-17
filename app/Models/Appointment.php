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
        'app_id',
        'entity_name',
        'entity_id',
        'scheduled_at',
        'expected_end_time',
        'service_ids',
        'provider_id',
        'description',
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

    protected $casts = [
        'scheduled_at'      => 'datetime',
        'expected_end_time' => 'datetime',
        'service_ids'       => 'array',
    ];

    /**
     * Relação polimórfica com a entidade (Barbershop, Hospital, Dentist, etc.)
     */
    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_name', 'entity_id');
    }

    /**
     * O usuário genérico que presta o serviço.
     */
    public function providerUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'provider_id');
    }

    /**
     * Retorna o perfil específico do prestador:
     * - Se for barbershop, pega ->barber
     * - Se for hospital, pega ->doctor
     * - Se for dentist, pega ->dentist
     */
    public function getProviderProfileAttribute()
    {
        $user = $this->providerUser;
        if (! $user) {
            return null;
        }

        switch (strtolower($this->entity_name)) {
            case 'barbershop':
                return $user->barber;
            case 'hospital':
                return $user->doctor;
            case 'dentist':
                return $user->dentist;
            default:
                return null;
        }
    }

    /**
     * Cliente que agendou (sempre um User)
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'client_id');
    }

    /**
     * Quem registrou o agendamento
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'registered_by');
    }

    /**
     * Retorna nomes dos serviços agendados
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
