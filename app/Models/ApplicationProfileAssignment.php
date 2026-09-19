<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationProfileAssignment extends Model
{
    protected $fillable = [
        'application_id',
        'user_id',
        'profile_id',
        'scope_type',
        'scope_id',
        'status',
        'source',
        'metadata',
        'granted_by_user_id',
        'revoked_at',
    ];

    protected $casts = [
        'scope_id' => 'integer',
        'metadata' => 'array',
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
        return $this->belongsTo(ApplicationProfile::class, 'profile_id');
    }

    public function grantedBy()
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
