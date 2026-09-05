<?php

namespace App\Models;

use App\Domain\Organizations\Support\OrganizationTaxonomy;
use App\Services\LocationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compatibility alias for the legacy production vocabulary.
 *
 * Production has no independent persistence. Every read and write targets the
 * generic establishments table with category=production, and legacy type
 * values are normalized to the canonical organization taxonomy before save.
 */
class Production extends Establishment
{
    protected $table = 'establishments';

    protected $fillable = [
        'app_id','app_slug','legacy_production_id','name','slug','type','roles','category','phone','establishment_type',
        'description','city_id','city','uf','location','cep','address','address_number','neighborhood',
        'address_complement','address_reference','formatted_address','latitude','longitude','place_id',
        'google_maps_url','location_public','user_id','created_by','updated_by','is_featured','is_published',
        'is_approved','is_cancelled','additional_info','facebook_url','twitter_url','instagram_url','youtube_url',
        'other_information','ticket_price_min','ticket_price_max','total_tickets_sold','total_tickets_available',
        'logo','background','segments','website_url','cnpj','fantasy','country','capacity','start_date','end_date',
        'contact_email','contact_phone','images','email',
    ];

    protected $casts = [
        'roles' => 'array',
        'segments' => 'array',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'is_approved' => 'boolean',
        'is_cancelled' => 'boolean',
        'location_public' => 'boolean',
        'ticket_price_min' => 'decimal:2',
        'ticket_price_max' => 'decimal:2',
        'total_tickets_sold' => 'integer',
        'total_tickets_available' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'city_id' => 'integer',
        'legacy_production_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('production', function (Builder $query) {
            $query->where($query->qualifyColumn('category'), 'production');
        });

        static::creating(function (Production $production) {
            $production->category = 'production';
            $production->type = OrganizationTaxonomy::normalizeType($production->type);
            $production->roles = OrganizationTaxonomy::normalizeRoles($production->roles, $production->type);
            $production->establishment_type = $production->establishment_type ?: 'production';
            $production->fantasy = $production->fantasy ?: $production->name;
            $production->created_by = $production->created_by ?: $production->user_id;
            $production->updated_by = $production->updated_by ?: $production->user_id;

            if (! $production->app_slug && $production->app_id) {
                $production->app_slug = Application::query()->whereKey($production->app_id)->value('slug');
            }
        });

        static::saving(function (Production $production) {
            foreach (['city_id','cep','address_number','neighborhood','address_complement','address_reference','formatted_address','latitude','longitude','place_id','google_maps_url','location_public'] as $field) {
                if (request()->exists($field)) $production->setAttribute($field, request()->input($field));
            }

            if (request()->exists('city') && ! request()->exists('city_id')) {
                $production->city_id = null;
            }

            $locationDirty = ! $production->exists || $production->isDirty(['city_id','city','uf','cep']);
            if ($locationDirty) {
                $service = app(LocationService::class);
                if ($production->cep) $production->cep = $service->normalizeCep($production->cep);
                if ($production->city_id || $production->city || $production->uf) {
                    $data = ['city_id'=>$production->city_id,'city'=>$production->city,'uf'=>$production->uf];
                    $service->applyCanonicalCity($data, true);
                    $production->city_id = $data['city_id'];
                    $production->city = $data['city'];
                    $production->uf = $data['uf'];
                }
            }

            $production->category = 'production';
            $production->type = OrganizationTaxonomy::normalizeType($production->type);
            $production->roles = OrganizationTaxonomy::normalizeRoles($production->roles, $production->type);
            $production->establishment_type = $production->establishment_type ?: 'production';
            $production->updated_by = $production->updated_by ?: $production->user_id;
            if (! $production->phone && $production->contact_phone) $production->phone = $production->contact_phone;
            if (! $production->email && $production->contact_email) $production->email = $production->contact_email;
        });

        static::saved(function (Production $production) {
            if (! $production->app_id || ! Schema::hasTable('application_establishment')) return;

            DB::table('application_establishment')->updateOrInsert(
                ['application_id' => $production->app_id, 'establishment_id' => $production->id],
                ['is_primary' => true, 'created_at' => $production->created_at ?: now(), 'updated_at' => now()]
            );
        });
    }

    public function application(){return $this->belongsTo(Application::class,'app_id');}
    public function municipality(){return $this->belongsTo(BrazilianMunicipality::class,'city_id','ibge_code');}
    public function interactions(){return $this->hasMany(Interaction::class,'entity_id')->whereIn('entity_type',['production','Production','Establishment','Organization']);}
    public function events(){return $this->hasMany(Event::class,'production_id')->orderBy('start_date','desc');}

    /**
     * Production is a compatibility subclass of Establishment. Eloquent would
     * otherwise infer production_id for this inherited relationship from the
     * runtime model class, while employers are stored against establishment_id.
     */
    public function employers(){return $this->hasMany(Employer::class,'establishment_id');}

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
