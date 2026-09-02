<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OauthClient extends Model
{
    protected $fillable = ['client_id', 'api_project_id', 'name', 'secret_hash', 'scopes', 'is_active', 'last_used_at', 'revoked_at'];
    protected $hidden = ['secret_hash'];
    protected $casts = ['scopes' => 'array', 'is_active' => 'boolean', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (OauthClient $client) => $client->client_id ??= (string) Str::uuid());
    }

    public function project() { return $this->belongsTo(ApiProject::class, 'api_project_id'); }

    public static function issue(ApiProject $project, string $name, array $scopes = ['*']): array
    {
        $secret = 'pts_' . Str::lower(Str::random(48));
        $client = static::create([
            'api_project_id' => $project->id,
            'name' => $name,
            'secret_hash' => hash('sha256', $secret),
            'scopes' => $scopes,
        ]);

        return [$client, $secret];
    }

    public function valid(): bool
    {
        return $this->is_active && ! $this->revoked_at;
    }
}
