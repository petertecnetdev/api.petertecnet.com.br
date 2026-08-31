<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

class Event extends Model
{
    protected $fillable = [
        'app_id', 'app_slug', 'production_id', 'title', 'description', 'category', 'image', 'address', 'google_maps_url',
        'start_date', 'end_date', 'venue', 'uf', 'establishment_type', 'slug', 'city',
        'state', 'country', 'location', 'cep', 'latitude', 'longitude', 'is_featured',
        'is_published', 'is_approved', 'is_cancelled', 'max_attendees', 'remaining_tickets',
        'extra_info', 'agenda', 'menu', 'additional_info', 'facebook_url', 'twitter_url',
        'instagram_url', 'youtube_url', 'contact_email', 'contact_phone', 'website',
        'registration_link', 'organizer_name', 'organizer_email', 'organizer_phone',
        'organizer_description', 'speaker_list', 'sponsor_list', 'partners', 'reviews',
        'rating', 'is_private', 'requires_approval', 'approval_message', 'segments',
        'establishment_name',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'is_approved' => 'boolean',
        'is_cancelled' => 'boolean',
        'is_private' => 'boolean',
        'requires_approval' => 'boolean',
        'extra_info' => 'array',
        'agenda' => 'array',
        'menu' => 'array',
        'additional_info' => 'array',
        'speaker_list' => 'array',
        'sponsor_list' => 'array',
        'partners' => 'array',
        'reviews' => 'array',
        'segments' => 'array',
        'rating' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'max_attendees' => 'integer',
        'remaining_tickets' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (Event $event) {
            if ($event->app_slug !== 'cutinapp') return;
            if ($event->google_maps_url) $event->google_maps_url = trim((string) $event->google_maps_url);
            if (! $event->start_date || ! $event->end_date) return;

            $start = Carbon::parse($event->start_date, config('app.timezone'));
            $end = Carbon::parse($event->end_date, config('app.timezone'));
            if ($event->isDirty('start_date') && $start->lt(now()->subMinutes(1))) {
                throw ValidationException::withMessages(['start_date' => ['O início do evento não pode ficar no passado.']]);
            }
            if (! $end->gt($start)) {
                throw ValidationException::withMessages(['end_date' => ['O término do evento precisa ser posterior ao início.']]);
            }
        });
    }

    public function application() { return $this->belongsTo(Application::class, 'app_id'); }
    public function production() { return $this->belongsTo(Production::class); }
    public function tickets() { return $this->hasMany(Ticket::class); }

    public function artists()
    {
        return $this->belongsToMany(CutinappArtist::class, 'cutinapp_event_artist', 'event_id', 'artist_id')
            ->withPivot(['participation_type', 'stage', 'scheduled_at', 'description', 'sort_order', 'is_headliner'])
            ->withTimestamps();
    }

    public function interactions()
    {
        return $this->hasMany(Interaction::class, 'entity_id')->where('entity_type', 'event');
    }

    public function getSegmentsnNamesAttribute()
    {
        $assigned = is_array($this->segments) ? $this->segments : [];
        if ($assigned === []) return '<i>Nenhum segmento atribuído</i>';
        $names = [];
        $segments = Config::get('segments', []);
        foreach ($assigned as $key) if (isset($segments[$key]['name'])) $names[] = $segments[$key]['name'];
        return implode(' | ', $names);
    }
}
