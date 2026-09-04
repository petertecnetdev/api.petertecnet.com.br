<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAcquisitionCommission extends Model
{
    protected $fillable = [
        'application_id',
        'agent_user_id',
        'referral_id',
        'event_id',
        'percentage',
        'basis',
    ];

    protected $casts = [
        'application_id' => 'integer',
        'agent_user_id' => 'integer',
        'referral_id' => 'integer',
        'event_id' => 'integer',
        'percentage' => 'decimal:2',
    ];

    public function application(){ return $this->belongsTo(Application::class); }
    public function agent(){ return $this->belongsTo(User::class, 'agent_user_id'); }
    public function referral(){ return $this->belongsTo(AcquisitionReferral::class, 'referral_id'); }
    public function event(){ return $this->belongsTo(Event::class); }
}
