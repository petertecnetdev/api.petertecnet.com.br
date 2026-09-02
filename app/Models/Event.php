<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class Event extends Model
{
    protected $fillable = ['app_id','app_slug','production_id','title','description','category','image','event_format','address','address_number','neighborhood','address_complement','address_reference','formatted_address','place_id','google_maps_url','online_platform','online_url','online_instructions','start_date','end_date','venue','city_id','uf','establishment_type','slug','city','state','country','location','cep','latitude','longitude','is_featured','is_published','is_approved','is_cancelled','max_attendees','remaining_tickets','extra_info','agenda','menu','additional_info','facebook_url','twitter_url','instagram_url','youtube_url','contact_email','contact_phone','website','registration_link','organizer_name','organizer_email','organizer_phone','organizer_description','speaker_list','sponsor_list','partners','reviews','rating','is_private','requires_approval','approval_message','segments','establishment_name'];

    protected $casts = ['start_date'=>'datetime','end_date'=>'datetime','is_featured'=>'boolean','is_published'=>'boolean','is_approved'=>'boolean','is_cancelled'=>'boolean','is_private'=>'boolean','requires_approval'=>'boolean','extra_info'=>'array','agenda'=>'array','menu'=>'array','additional_info'=>'array','speaker_list'=>'array','sponsor_list'=>'array','partners'=>'array','reviews'=>'array','segments'=>'array','rating'=>'decimal:2','latitude'=>'decimal:7','longitude'=>'decimal:7','max_attendees'=>'integer','remaining_tickets'=>'integer','city_id'=>'integer'];

    public function application() { return $this->belongsTo(Application::class, 'app_id'); }
    public function production() { return $this->belongsTo(Production::class); }
    public function municipality() { return $this->belongsTo(BrazilianMunicipality::class, 'city_id', 'ibge_code'); }
    public function tickets() { return $this->hasMany(Ticket::class); }
    public function artists() { return $this->belongsToMany(Artist::class, 'cutinapp_event_artist', 'event_id', 'artist_id')->withPivot(['participation_type','stage','scheduled_at','description','sort_order','is_headliner'])->withTimestamps(); }
    public function interactions() { return $this->hasMany(Interaction::class, 'entity_id')->where('entity_type', 'event'); }

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
