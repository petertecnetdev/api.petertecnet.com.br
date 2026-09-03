<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderStatementReport extends Model
{
    protected $fillable = [
        'provider', 'report_type', 'window_key', 'provider_task_id', 'provider_report_id',
        'file_name', 'period_start', 'period_end', 'status', 'requested_at', 'processed_at',
        'imported_at', 'error_message', 'metadata',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
        'imported_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(ProviderStatementEntry::class, 'report_id');
    }
}
