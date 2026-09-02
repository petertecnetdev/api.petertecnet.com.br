<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationAccess extends Model
{
    protected $table = 'application_accesses';

    protected $fillable = [
        'application_id', 'user_id', 'organization_id', 'role', 'scopes', 'status', 'granted_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'granted_at' => 'datetime',
    ];

    public function application() { return $this->belongsTo(Application::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function organization() { return $this->belongsTo(Organization::class); }

    public function allows(string $scope): bool
    {
        $scopes = $this->scopes ?: [];
        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }
}
