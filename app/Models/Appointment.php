<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Models\Barber;

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
        'client_id',
        'registered_by',
        'status',
        'location',
        'duration',
        'notes',
        'payment_status',
        'appointment_type',
        'attendance_status',
    ];

    protected $casts = [
        'scheduled_at'      => 'datetime',
        'expected_end_time' => 'datetime',
        'service_ids'       => 'array',
    ];

    /**
     * O usuário genérico que presta o serviço.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    /**
     * Se for barbeiro, o perfil dele.
     * (no seu User model já existe hasOne(Barber::class))
     */
    public function barberProfile()
    {
        return $this->provider->barber();
    }

    /**
     * Entidade polimórfica (Barbershop, futuramente Hospital etc).
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Extrai nomes dos serviços.
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
