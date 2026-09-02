<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamMembership extends Model
{
    protected $fillable = ['team_id','person_profile_id','role','status','metadata','joined_at'];
    protected $casts = ['metadata'=>'array','joined_at'=>'datetime'];

    public function team() { return $this->belongsTo(Team::class); }
    public function person() { return $this->belongsTo(PeopleProfile::class, 'person_profile_id'); }
}
