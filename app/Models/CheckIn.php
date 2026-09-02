<?php

namespace App\Models;

use App\Models\Traits\BelongsToApplicationContext;
use Illuminate\Database\Eloquent\Model;

class CheckIn extends Model
{
    use BelongsToApplicationContext;

    protected $fillable = [
        'application_id','admission_credential_id','actor_user_id','result',
        'device_id','request_id','metadata','checked_in_at',
    ];

    protected $casts = ['metadata'=>'array','checked_in_at'=>'datetime'];

    protected function applicationContextColumn(): string
    {
        return 'application_id';
    }

    public function application() { return $this->belongsTo(Application::class); }
    public function credential() { return $this->belongsTo(AdmissionCredential::class, 'admission_credential_id'); }
    public function actor() { return $this->belongsTo(User::class, 'actor_user_id'); }
}
