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
        'branding',
        'branding_draft',
        'branding_version',
        'branding_updated_at',
        'branding_published_at',
        'is_active',
        'self_service_access',
        'launcher_order',
        'category',
        'is_visible',
        'is_default',
        'operational_status',
        'maintenance_message',
        'ecosystem_sdk_version',
        'version',
        'author',
        'release_date',
    ];

    protected $casts = [
        'branding' => 'array',
        'branding_draft' => 'array',
        'branding_version' => 'integer',
        'branding_updated_at' => 'datetime',
        'branding_published_at' => 'datetime',
        'is_active' => 'boolean',
        'self_service_access' => 'boolean',
        'is_visible' => 'boolean',
        'is_default' => 'boolean',
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

    public function brandingRevisions()
    {
        return $this->hasMany(ApplicationBrandingRevision::class);
    }
}
