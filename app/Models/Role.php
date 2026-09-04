<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['code', 'name', 'description', 'is_system', 'metadata'];

    protected $casts = [
        'is_system' => 'boolean',
        'metadata' => 'array',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withTimestamps();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }
}
