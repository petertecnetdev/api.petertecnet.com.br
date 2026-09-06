<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationAdminAudit extends Model
{
    protected $fillable = [
        'application_id',
        'actor_user_id',
        'target_user_id',
        'action',
        'metadata',
        'request_id',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function target()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
