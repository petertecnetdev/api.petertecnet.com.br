<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserInvitation extends Model
{
    protected $fillable = [
        'user_id',
        'application_id',
        'invited_by',
        'token_hash',
        'verification_code_hash',
        'status',
        'expires_at',
        'consumed_at',
        'revoked_at',
        'metadata',
    ];

    protected $hidden = [
        'token_hash',
        'verification_code_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isUsable(): bool
    {
        return $this->status === 'pending'
            && ! $this->consumed_at
            && ! $this->revoked_at
            && $this->expires_at
            && now()->lessThanOrEqualTo($this->expires_at);
    }
}
