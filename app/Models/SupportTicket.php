<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'access_token_hash',
        'user_id',
        'application_id',
        'establishment_id',
        'assigned_to_user_id',
        'requester_name',
        'requester_email',
        'requester_phone',
        'subject',
        'category',
        'priority',
        'status',
        'channel',
        'source_url',
        'metadata',
        'last_message_at',
        'first_response_at',
        'resolved_at',
        'closed_at',
    ];

    protected $hidden = [
        'access_token_hash',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_message_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function messages()
    {
        return $this->hasMany(SupportMessage::class)->orderBy('created_at');
    }
}
