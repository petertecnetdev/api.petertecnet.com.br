<?php

namespace App\Models;

use App\Models\Traits\BelongsToApplicationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Team extends Model
{
    use BelongsToApplicationContext, SoftDeletes;

    protected $fillable = [
        'application_id', 'organization_id', 'owner_user_id', 'name', 'slug',
        'type', 'status', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    protected function applicationContextColumn(): string
    {
        return 'application_id';
    }

    public function application() { return $this->belongsTo(Application::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function owner() { return $this->belongsTo(User::class, 'owner_user_id'); }
    public function members() { return $this->belongsToMany(PeopleProfile::class, 'team_memberships', 'team_id', 'person_profile_id')->withPivot(['role','status','metadata','joined_at'])->withTimestamps(); }
}
