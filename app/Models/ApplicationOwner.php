<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationOwner extends Model
{
    protected $fillable = [
        'application_id',
        'user_id',
        'bootstrap_email',
        'bound_at',
        'bound_by_user_id',
    ];

    protected $casts = [
        'bound_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
