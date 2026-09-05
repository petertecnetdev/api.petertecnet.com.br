<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppMessageAttachment extends Model
{
    protected $fillable = [
        'uuid',
        'message_id',
        'kind',
        'original_name',
        'extension',
        'mime_type',
        'file_size',
        'storage_disk',
        'storage_path',
        'metadata',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'storage_disk',
        'storage_path',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(AppMessage::class, 'message_id');
    }
}
