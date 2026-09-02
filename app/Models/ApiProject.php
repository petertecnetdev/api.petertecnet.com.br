<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiProject extends Model
{
    protected $fillable = [
        'public_id', 'application_id', 'owner_user_id', 'organization_id', 'name',
        'environment', 'status', 'requests_per_minute', 'monthly_request_quota',
        'allowed_scopes', 'metadata',
    ];

    protected $casts = ['allowed_scopes' => 'array', 'metadata' => 'array'];

    protected static function booted(): void
    {
        static::creating(fn (ApiProject $project) => $project->public_id ??= (string) Str::uuid());
    }

    public function application() { return $this->belongsTo(Application::class); }
    public function credentials() { return $this->hasMany(ApiCredential::class); }
    public function webhooks() { return $this->hasMany(WebhookEndpoint::class); }

    public function allows(string $scope): bool
    {
        $allowed = $this->allowed_scopes ?: [];
        return in_array('*', $allowed, true) || in_array($scope, $allowed, true);
    }
}
