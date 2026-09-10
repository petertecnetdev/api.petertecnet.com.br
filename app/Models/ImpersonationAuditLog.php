<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImpersonationAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'impersonation_session_id', 'actor_user_id', 'effective_user_id', 'application_id',
        'method', 'path', 'route_name', 'status_code', 'action', 'entity_type', 'entity_id',
        'ip_address', 'user_agent', 'context', 'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ImpersonationSession::class, 'impersonation_session_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function effectiveUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'effective_user_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }
}
