<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationAdminAssignment extends Model
{
    protected $fillable = [
        'application_id',
        'user_id',
        'profile_id',
        'granted_by_user_id',
        'status',
        'revoked_at',
    ];

    protected $casts = [
        'revoked_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function profile()
    {
        return $this->belongsTo(ApplicationAdminProfile::class, 'profile_id');
    }

    public function grantedBy()
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
