<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationMembership extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'role', 'scopes', 'status', 'joined_at'];
    protected $casts = ['scopes' => 'array', 'joined_at' => 'datetime'];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function user() { return $this->belongsTo(User::class); }

    public function allows(string $scope): bool
    {
        $scopes = $this->scopes ?: [];
        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }
}
