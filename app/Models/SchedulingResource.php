<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchedulingResource extends Model
{
    use HasFactory;

    public const TYPES = [
        'professional',
        'room',
        'station',
        'equipment',
        'vehicle',
        'space',
        'other',
    ];

    protected $fillable = [
        'app_id',
        'establishment_id',
        'employer_id',
        'type',
        'name',
        'description',
        'capacity',
        'is_active',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(SchedulingResourceSchedule::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'scheduling_resource_item')
            ->withTimestamps();
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'order_scheduling_resource')
            ->withPivot('role')
            ->withTimestamps();
    }
}
