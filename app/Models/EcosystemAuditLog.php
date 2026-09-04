<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EcosystemAuditLog extends Model
{
    protected $fillable = [
        'user_id', 'action', 'entity_type', 'entity_id', 'before', 'after', 'ip', 'user_agent',
        'request_id', 'application_id', 'establishment_id', 'metadata',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'metadata' => 'array',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function application() { return $this->belongsTo(Application::class); }
    public function establishment() { return $this->belongsTo(Establishment::class); }
}
