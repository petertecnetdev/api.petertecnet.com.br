<?php

namespace App\Models;

use App\Models\Traits\BelongsToApplicationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PeopleProfile extends Model
{
    use BelongsToApplicationContext, SoftDeletes;

    protected $fillable = [
        'application_id', 'user_id', 'organization_id', 'kind', 'display_name',
        'slug', 'status', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    protected function applicationContextColumn(): string
    {
        return 'application_id';
    }

    public function application() { return $this->belongsTo(Application::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function teams() { return $this->belongsToMany(Team::class, 'team_memberships')->withPivot(['role','status','metadata','joined_at'])->withTimestamps(); }
}
