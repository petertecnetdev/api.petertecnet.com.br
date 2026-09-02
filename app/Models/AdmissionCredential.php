<?php

namespace App\Models;

use App\Models\Traits\BelongsToApplicationContext;
use Illuminate\Database\Eloquent\Model;

class AdmissionCredential extends Model
{
    use BelongsToApplicationContext;

    protected $fillable = [
        'public_id','application_id','admission_type_id','user_id','status','code_hash',
        'source_type','source_id','valid_from','valid_until','checked_in_at','metadata',
    ];

    protected $casts = [
        'valid_from'=>'datetime','valid_until'=>'datetime','checked_in_at'=>'datetime','metadata'=>'array',
    ];

    protected function applicationContextColumn(): string
    {
        return 'application_id';
    }

    public function application() { return $this->belongsTo(Application::class); }
    public function admissionType() { return $this->belongsTo(AdmissionType::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function checkIns() { return $this->hasMany(CheckIn::class); }
}
