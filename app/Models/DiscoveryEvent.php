<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiscoveryEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'session_id',
        'event_type',
        'entity_type',
        'entity_id',
        'path',
        'source',
        'referrer_host',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
