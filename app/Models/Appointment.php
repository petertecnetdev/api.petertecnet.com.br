<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
        'client_confirmation',
        'info',
    ];

    protected $casts = [
        // força o cast para datetime, assim scheduled_at é Carbon
        'scheduled_at' => 'datetime:Y-m-d H:i:s',
        'expected_end_time' => 'datetime:Y-m-d H:i:s',
        'service_ids' => 'array',
    ];
    /**
     * Quem presta o serviço: usuário genérico
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'provider_id');
    }
    /**
     * Se esse usuário for barbeiro, puxa o perfil Barber
     */
    public function barber(): BelongsTo
    {
        // provider_id em appointments → user_id em barbers
        return $this->belongsTo(\App\Models\Barber::class, 'provider_id', 'user_id');
    }

    /**
     * Relação polimórfica: usa as colunas entity_name + entity_id
     */
    public function entity(): MorphTo
    {
        // 1º arg: nome do método, 2º: coluna de tipo, 3º: coluna de id
        return $this->morphTo(__FUNCTION__, 'entity_name', 'entity_id');
    }
    public function client(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'client_id');
    }


    /**
     * Accessor para nomes legíveis de serviço
     */
    public function getServiceNamesAttribute(): array
    {
        $ids = $this->service_ids;
        // Se veio string, decodifica
        if (is_string($ids)) {
            $ids = json_decode($ids, true);
        }
        if (!is_array($ids) || empty($ids)) {
            return [];
        }
        return \App\Models\Item::whereIn('id', $ids)
            ->where('category', 'Serviços')
            ->pluck('name')
            ->toArray();
    }
}
