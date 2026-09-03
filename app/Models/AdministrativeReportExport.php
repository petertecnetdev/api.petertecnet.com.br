<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdministrativeReportExport extends Model
{
    protected $fillable = [
        'uuid', 'user_id', 'report_key', 'format', 'status', 'filters', 'filename', 'disk', 'path', 'row_count', 'error_message',
        'request_ip', 'request_user_agent', 'requested_at', 'started_at', 'completed_at', 'expires_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'requested_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $hidden = ['path', 'request_user_agent'];
    protected $appends = ['download_ready'];

    public function user() { return $this->belongsTo(User::class); }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function getDownloadReadyAttribute(): bool { return $this->status === 'ready' && ! $this->expires_at?->isPast(); }
}
