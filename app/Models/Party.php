<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Crypt;

class Party extends Model
{
    protected $fillable = [
        'type', 'user_id', 'legal_name', 'display_name', 'document_type', 'document', 'email', 'phone', 'metadata',
    ];

    protected $hidden = ['document_encrypted', 'document_hash'];

    protected $casts = ['metadata' => 'array'];

    public function setDocumentAttribute(?string $value): void
    {
        $normalized = self::normalizeDocument($value);
        $this->attributes['document'] = null;
        $this->attributes['document_encrypted'] = $normalized ? Crypt::encryptString(trim((string) $value)) : null;
        $this->attributes['document_hash'] = $normalized ? hash('sha256', $normalized) : null;
    }

    public function getDocumentAttribute(?string $legacyValue): ?string
    {
        $encrypted = $this->attributes['document_encrypted'] ?? null;
        if ($encrypted) {
            try {
                return Crypt::decryptString($encrypted);
            } catch (\Throwable) {
                return null;
            }
        }

        return $legacyValue;
    }

    public static function normalizeDocument(?string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    public static function documentHash(?string $value): ?string
    {
        $normalized = self::normalizeDocument($value);
        return $normalized !== '' ? hash('sha256', $normalized) : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'party_users')
            ->withPivot(['relationship_type', 'status', 'starts_at', 'ends_at', 'metadata'])
            ->withTimestamps();
    }
}
