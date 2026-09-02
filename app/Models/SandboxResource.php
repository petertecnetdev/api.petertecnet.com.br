<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SandboxResource extends Model
{
    protected $fillable = ['public_id', 'api_project_id', 'resource_type', 'payload'];
    protected $casts = ['payload' => 'array'];

    protected static function booted(): void
    {
        static::creating(fn (SandboxResource $resource) => $resource->public_id ??= (string) Str::uuid());
    }

    public function project() { return $this->belongsTo(ApiProject::class, 'api_project_id'); }
}
