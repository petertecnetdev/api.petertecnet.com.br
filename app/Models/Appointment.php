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
        'scheduled_at'       => 'datetime:Y-m-d H:i:s',
        'expected_end_time'  => 'datetime:Y-m-d H:i:s',
        'service_ids'        => 'array',
        'info'               => 'array',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class, 'provider_id', 'user_id');
    }

    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_name', 'entity_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function getServiceNamesAttribute(): array
    {
        $ids = $this->service_ids;

        if (is_string($ids)) {
            $ids = json_decode($ids, true);
        }

        if (!is_array($ids) || empty($ids)) {
            return [];
        }

        return Item::whereIn('id', $ids)
            ->where('category', 'Serviços')
            ->pluck('name')
            ->toArray();
    }
}
