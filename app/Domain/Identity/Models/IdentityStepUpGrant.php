<?php

namespace App\Domain\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class IdentityStepUpGrant extends Model
{
    protected $fillable = [
        'grant_id', 'user_id', 'session_id', 'token_hash', 'action', 'method',
        'ip_address', 'user_agent', 'expires_at', 'consumed_at', 'revoked_at', 'metadata',
    ];

    protected $hidden = ['token_hash'];

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

    public function session()
    {
        return $this->belongsTo(IdentitySession::class, 'session_id');
    }

    public function isActive(): bool
    {
        return $this->consumed_at === null
            && $this->revoked_at === null
            && $this->expires_at?->isFuture();
    }
}
