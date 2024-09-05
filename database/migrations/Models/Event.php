<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
class Event extends Model
{
    protected $fillable = [
        'production_id',
        'title',
        'description',
        'image',
        'address',
        'start_date',
        'end_date',
        'venue',
        'uf',
        'slug',
        'city',
        'state',
        'country',
        'location',
        'cep',
        'latitude',
        'longitude',
        'is_featured',
        'is_published',
        'is_approved',
        'is_cancelled',
        'max_attendees',
        'remaining_tickets',
        'extra_info',
        'agenda',
        'menu',
        'additional_info',
        'facebook_url',
        'twitter_url',
        'instagram_url',
        'youtube_url',
        'contact_email',
        'contact_phone',
        'website',
        'registration_link',
        'organizer_name',
        'organizer_email',
        'organizer_phone',
        'organizer_description',
        'speaker_list',
        'sponsor_list',
        'partners',
        'reviews',
        'rating',
        'is_private',
        'requires_approval',
        'approval_message',
        'segments'
    ];

    protected $casts = [
        'extra_info' => 'json',
        'agenda' => 'json',
        'menu' => 'json',
        'speaker_list' => 'json',
        'sponsor_list' => 'json',
        'partners' => 'json',
        'reviews' => 'json',
        'segments' => 'json',
    ];
    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }
    public function production()
    {
        return $this->belongsTo(Production::class);
    }
    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function items()
{
    return $this->hasMany(Item::class);
}
    
    public function interactions()
    {
        return $this->hasMany(Interaction::class, 'entity_id')->where('entity_type', 'event');
    }
    public function getSegmentsnNamesAttribute()
    {
        $segmentsArray = is_string($this->segments) ? json_decode($this->segments, true) : [];

        if (is_null($segmentsArray) || empty($segmentsArray) || count($segmentsArray) <= 0) {
            return '<i>Nenhum seguimento atribuido</i>';
        }

        $names = [];
        $segments = Config::get('segments'); // Certifique-se de ter definido seu arquivo de configuração

        foreach ($segmentsArray as $key) {
            if (isset($segments[$key])) {
                $names[] = $segments[$key]['name'];
            }
        }

        return implode(" | ", $names);
    }

    public function user()
{
    return $this->belongsTo(User::class);
}
}
