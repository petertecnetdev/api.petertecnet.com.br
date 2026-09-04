<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcquisitionReferral extends Model
{
    protected $fillable = [
        'application_id',
        'agent_user_id',
        'referred_user_id',
        'production_id',
        'email',
        'name',
        'token_hash',
        'activation_code_hash',
        'requires_password',
        'status',
        'expires_at',
        'accepted_at',
        'last_sent_at',
        'metadata',
    ];

    protected $hidden = ['token_hash', 'activation_code_hash'];

    protected $casts = [
        'application_id' => 'integer',
        'agent_user_id' => 'integer',
        'referred_user_id' => 'integer',
        'production_id' => 'integer',
        'requires_password' => 'boolean',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function application(){ return $this->belongsTo(Application::class); }
    public function agent(){ return $this->belongsTo(User::class, 'agent_user_id'); }
    public function referredUser(){ return $this->belongsTo(User::class, 'referred_user_id'); }
    public function production(){ return $this->belongsTo(Production::class); }
    public function commissions(){ return $this->hasMany(EventAcquisitionCommission::class, 'referral_id'); }
}
