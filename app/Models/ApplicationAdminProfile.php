<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationAdminProfile extends Model
{
    protected $fillable = [
        'application_id',
        'name',
        'slug',
        'description',
        'permissions',
        'is_system',
        'created_by_user_id',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system' => 'boolean',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignments()
    {
        return $this->hasMany(ApplicationAdminAssignment::class, 'profile_id');
    }
}
