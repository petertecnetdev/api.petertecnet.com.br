<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id', 'user_id', 'category', 'priority', 'status', 'subject', 'description',
        'route', 'app_version', 'device', 'correlation_id', 'first_responded_at', 'resolved_at', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'first_responded_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function application() { return $this->belongsTo(Application::class, 'application_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function interactions() { return $this->hasMany(Interaction::class, 'correlation_id', 'correlation_id'); }
}
