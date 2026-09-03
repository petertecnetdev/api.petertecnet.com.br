<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdentityDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'user_id', 'name', 'platform', 'browser', 'user_agent', 'last_ip_address',
        'trusted', 'first_seen_at', 'last_seen_at', 'metadata',
    ];

    protected $casts = [
        'trusted' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sessions()
    {
        return $this->hasMany(IdentitySession::class, 'device_id');
    }
}
