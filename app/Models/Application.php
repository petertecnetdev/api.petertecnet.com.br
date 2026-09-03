<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'slug',
        'url',
        'logo',
        'is_active',
        'self_service_access',
        'launcher_order',
        'category',
        'is_visible',
        'operational_status',
        'maintenance_message',
        'ecosystem_sdk_version',
        'version',
        'author',
        'release_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'self_service_access' => 'boolean',
        'is_visible' => 'boolean',
        'launcher_order' => 'integer',
        'release_date' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVisibleInLauncher($query)
    {
        return $query->where('is_visible', true);
    }

    public function isOperational(): bool
    {
        return ! in_array($this->operational_status, ['maintenance', 'down'], true);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'app_id');
    }

    public function establishments()
    {
        return $this->hasMany(Establishment::class, 'app_id');
    }

    public function items()
    {
        return $this->hasMany(Item::class, 'app_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'application_user')
            ->withPivot(['role', 'status', 'metadata', 'joined_at'])
            ->withTimestamps();
    }
}
