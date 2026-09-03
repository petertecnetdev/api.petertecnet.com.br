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
        'version',
        'author',
        'release_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'self_service_access' => 'boolean',
        'release_date' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
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
