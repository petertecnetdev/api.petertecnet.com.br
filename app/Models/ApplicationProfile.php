<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationProfile extends Model
{
    protected $fillable = [
        'application_id',
        'slug',
        'name',
        'description',
        'permissions',
        'is_system',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system' => 'boolean',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function assignments()
    {
        return $this->hasMany(ApplicationProfileAssignment::class, 'profile_id');
    }
}
