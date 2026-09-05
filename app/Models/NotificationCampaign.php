<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationCampaign extends Model
{
    protected $fillable = [
        'created_by_user_id',
        'app_id',
        'audience_type',
        'recipient_user_ids',
        'type',
        'title',
        'message',
        'reference_url',
        'data',
        'status',
        'recipients_count',
        'sent_at',
    ];

    protected $casts = [
        'recipient_user_ids' => 'array',
        'data' => 'array',
        'recipients_count' => 'integer',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function application()
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function notifications()
    {
        return $this->hasMany(AppNotification::class, 'campaign_id');
    }
}
