<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Organization extends Model
{
    use SoftDeletes;

    protected $fillable = ['owner_user_id', 'name', 'slug', 'type', 'document', 'status', 'metadata'];
    protected $casts = ['metadata' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (Organization $organization) {
            $organization->slug = $organization->slug ?: Str::slug($organization->name) . '-' . Str::lower(Str::random(6));
        });
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot(['role', 'scopes', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function applications()
    {
        return $this->belongsToMany(Application::class, 'application_accesses')
            ->withPivot(['role', 'scopes', 'status', 'granted_at'])
            ->withTimestamps();
    }
}
