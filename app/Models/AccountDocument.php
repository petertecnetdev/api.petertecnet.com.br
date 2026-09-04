<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class AccountDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'user_id', 'category', 'side', 'label', 'original_name', 'mime_type', 'extension',
        'file_size', 'sha256', 'storage_disk', 'storage_path', 'status', 'expires_on', 'metadata',
        'verified_at', 'verified_by', 'last_downloaded_at', 'download_count',
    ];

    protected $hidden = ['storage_path', 'sha256'];

    protected $casts = [
        'metadata' => 'array',
        'expires_on' => 'date',
        'verified_at' => 'datetime',
        'last_downloaded_at' => 'datetime',
        'download_count' => 'integer',
        'file_size' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function effectiveStatus(): string
    {
        if ($this->expires_on && $this->expires_on->isPast()) {
            return 'expired';
        }

        return $this->status ?: 'pending';
    }

    public function existsOnDisk(): bool
    {
        return Storage::disk($this->storage_disk ?: 'local')->exists($this->storage_path);
    }

    public function safePayload(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'category' => $this->category,
            'side' => $this->side,
            'label' => $this->label,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'file_size' => (int) $this->file_size,
            'status' => $this->effectiveStatus(),
            'expires_on' => $this->expires_on?->toDateString(),
            'metadata' => $this->metadata ?: [],
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'download_count' => (int) $this->download_count,
        ];
    }
}
