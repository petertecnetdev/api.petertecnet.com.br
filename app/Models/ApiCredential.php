<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiCredential extends Model
{
    protected $fillable = ['api_project_id', 'name', 'key_prefix', 'secret_hash', 'scopes', 'last_used_at', 'expires_at', 'revoked_at'];
    protected $casts = ['scopes' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    protected $hidden = ['secret_hash'];

    public function project() { return $this->belongsTo(ApiProject::class, 'api_project_id'); }

    public static function issue(ApiProject $project, string $name, array $scopes = ['*']): array
    {
        $prefix = $project->environment === 'sandbox' ? 'pt_test_' : 'pt_live_';
        $secret = $prefix . Str::lower(Str::random(40));

        $credential = static::create([
            'api_project_id' => $project->id,
            'name' => $name,
            'key_prefix' => substr($secret, 0, 16),
            'secret_hash' => hash('sha256', $secret),
            'scopes' => $scopes,
        ]);

        return [$credential, $secret];
    }

    public function valid(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function allows(string $scope): bool
    {
        $scopes = $this->scopes ?: [];
        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }
}
