<?php

namespace App\Models;

use App\Services\CutinappLocationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class Production extends Model
{
    protected $fillable = ['app_id','app_slug','name','slug','type','phone','establishment_type','description','city_id','city','uf','location','cep','address','address_number','neighborhood','address_complement','address_reference','formatted_address','latitude','longitude','place_id','google_maps_url','location_public','user_id','is_featured','is_published','is_approved','is_cancelled','additional_info','facebook_url','twitter_url','instagram_url','youtube_url','other_information','ticket_price_min','ticket_price_max','total_tickets_sold','total_tickets_available','logo','background','segments','website_url','cnpj','fantasy'];
    protected $casts = ['segments'=>'array','is_featured'=>'boolean','is_published'=>'boolean','is_approved'=>'boolean','is_cancelled'=>'boolean','location_public'=>'boolean','ticket_price_min'=>'decimal:2','ticket_price_max'=>'decimal:2','total_tickets_sold'=>'integer','total_tickets_available'=>'integer','latitude'=>'decimal:7','longitude'=>'decimal:7','city_id'=>'integer'];

    protected static function booted(): void
    {
        static::saving(function (Production $production) {
            if ($production->app_slug !== 'cutinapp') return;
            foreach (['city_id','cep','address_number','neighborhood','address_complement','address_reference','formatted_address','latitude','longitude','place_id','google_maps_url','location_public'] as $field) {
                if (request()->exists($field)) $production->setAttribute($field, request()->input($field));
            }
            $locationDirty=!$production->exists||$production->isDirty(['city_id','city','uf','cep']);
            if(!$locationDirty)return;
            $service=app(CutinappLocationService::class);
            if($production->cep)$production->cep=$service->normalizeCep($production->cep);
            if($production->city_id||$production->city||$production->uf){$data=['city_id'=>$production->city_id,'city'=>$production->city,'uf'=>$production->uf];$service->applyCanonicalCity($data,true);$production->city_id=$data['city_id'];$production->city=$data['city'];$production->uf=$data['uf'];}
        });
    }

    public function application(){return $this->belongsTo(Application::class,'app_id');} public function user(){return $this->belongsTo(User::class);} public function municipality(){return $this->belongsTo(BrazilianMunicipality::class,'city_id','ibge_code');} public function interactions(){return $this->hasMany(Interaction::class,'entity_id')->where('entity_type','production');}
    public function getSegmentsnNamesAttribute(){ $assigned=is_array($this->segments)?$this->segments:[];if($assigned===[])return '<i>Nenhum segmento atribuído</i>';$names=[];$segments=Config::get('segments',[]);foreach($assigned as $key)if(isset($segments[$key]['name']))$names[]=$segments[$key]['name'];return implode(' | ',$names);} public function events(){return $this->hasMany(Event::class)->orderBy('start_date','desc');}
}
