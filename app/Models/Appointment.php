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
     * Relaciona ao usuário genérico que presta o serviço. 
     */
    public function providerUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'provider_id');
    }

    /**
     * Perfil de barbeiro, se existir.
     */
    public function barber()
    {
        return $this->hasOne(Barber::class, 'user_id', 'provider_id');
    }

    /**
     * Perfil de médico, se existir.
     */
    public function doctor()
    {
        return $this->hasOne(Doctor::class, 'user_id', 'provider_id');
    }

    /**
     * Perfil de dentista, se existir.
     */
    public function dentist()
    {
        return $this->hasOne(Dentist::class, 'user_id', 'provider_id');
    }

    /**
     * Acesso “virtual” ao perfil ativo: barbeiro, médico, dentista...
     */
    public function getProviderProfileAttribute()
    {
        return $this->barber
             ?? $this->doctor
             ?? $this->dentist;
    }

    /**  
     * Entidade polimórfica (Barbershop, Hospital, Clinic…).
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Nomes dos serviços (pluck dos items).
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
