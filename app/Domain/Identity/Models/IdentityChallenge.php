<?php

namespace App\Domain\Identity\Models;

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class IdentityChallenge extends Model
{
    protected $fillable = [
        'token_hash', 'purpose', 'user_id', 'app_id', 'payload', 'attempts',
        'ip_address', 'user_agent', 'expires_at', 'consumed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function application()
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function canBeConsumed(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
