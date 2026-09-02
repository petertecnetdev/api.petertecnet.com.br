<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    protected $fillable = ['api_project_id', 'url', 'secret', 'events', 'is_active', 'failure_count', 'last_success_at'];
    protected $hidden = ['secret'];
    protected $casts = [
        'secret' => 'encrypted',
        'events' => 'array',
        'is_active' => 'boolean',
        'last_success_at' => 'datetime',
    ];

    public function project() { return $this->belongsTo(ApiProject::class, 'api_project_id'); }
    public function deliveries() { return $this->hasMany(WebhookDelivery::class); }

    public function listensTo(string $event): bool
    {
        $events = $this->events ?: [];
        return in_array('*', $events, true) || in_array($event, $events, true);
    }
}
