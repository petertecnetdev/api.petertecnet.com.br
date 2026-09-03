<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationBrandingRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'application_id',
        'version',
        'branding',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'branding' => 'array',
        'created_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
