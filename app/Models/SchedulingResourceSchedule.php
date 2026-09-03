<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchedulingResourceSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'scheduling_resource_id',
        'day_of_week',
        'reserved_date',
        'start_time',
        'end_time',
        'type',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'reserved_date' => 'date',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(SchedulingResource::class, 'scheduling_resource_id');
    }
}
