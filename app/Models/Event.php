<?php

namespace App\Models;

use App\Domain\Locations\Services\MunicipalityService;
use App\Services\EventAudienceService;
use App\Services\EventLineupNotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Event extends Model
{
    protected $attributes = ['is_private' => false];

    protected $fillable = ['app_id','app_slug','production_id','title','description','category','image','event_format','address','address_number','neighborhood','address_complement','address_reference','formatted_address','place_id','google_maps_url','online_platform','online_url','online_instructions','start_date','end_date','venue','city_id','uf','establishment_type','slug','city','state','country','location','cep','latitude','longitude','is_featured','is_published','is_approved','is_cancelled','max_attendees','remaining_tickets','extra_info','agenda','menu','additional_info','facebook_url','twitter_url','instagram_url','youtube_url','contact_email','contact_phone','website','registration_link','organizer_name','organizer_email','organizer_phone','organizer_description','speaker_list','sponsor_list','partners','reviews','rating','is_private','requires_approval','approval_message','segments','establishment_name'];
    protected $casts = ['start_date'=>'datetime','end_date'=>'datetime','is_featured'=>'boolean','is_published'=>'boolean','is_approved'=>'boolean','is_cancelled'=>'boolean','is_private'=>'boolean','requires_approval'=>'boolean','extra_info'=>'array','agenda'=>'array','menu'=>'array','additional_info'=>'array','speaker_list'=>'array','sponsor_list'=>'array','partners'=>'array','reviews'=>'array','segments'=>'array','rating'=>'decimal:2','latitude'=>'decimal:7','longitude'=>'decimal:7','max_attendees'=>'integer','remaining_tickets'=>'integer','city_id'=>'integer'];
    protected $appends = ['temporal_status','has_started','has_ended','is_happening_now','sales_closed','allowed_actions'];

    public function scopePubliclyVisible($query)
    {
        return $query
            ->where('is_published', true)
            ->where(fn ($status) => $status->where('is_cancelled', false)->orWhereNull('is_cancelled'))
            ->where(fn ($privacy) => $privacy->where('is_private', false)->orWhereNull('is_private'));
    }

    protected static function booted(): void
    {
        static::saving(function (Event $event) {
            if ($event->getAttribute('is_private') === null) $event->setAttribute('is_private', false);

            foreach (['event_format','city_id','cep','address_number','neighborhood','address_complement','address_reference','formatted_address','place_id','latitude','longitude','google_maps_url','online_platform','online_url','online_instructions'] as $field) {
                if (request()->exists($field)) $event->setAttribute($field, request()->input($field));
            }

            if (request()->exists('city') && ! request()->exists('city_id')) $event->city_id = null;
            $event->event_format = $event->event_format ?: 'in_person';
            if (! in_array($event->event_format, ['in_person','online','hybrid'], true)) throw ValidationException::withMessages(['event_format' => ['Selecione presencial, online ou híbrido.']]);

            $physical = in_array($event->event_format, ['in_person','hybrid'], true);
            $locationDirty = ! $event->exists || $event->isDirty(['city_id','city','uf','cep','event_format']);
            if ($locationDirty) {
                $service = app(MunicipalityService::class);
                if ($event->cep) $event->cep = $service->normalizeCep($event->cep);
                if ($physical) {
                    $data = ['city_id'=>$event->city_id,'city'=>$event->city,'uf'=>$event->uf];
                    $service->applyCanonicalCity($data, true);
                    $event->city_id = $data['city_id']; $event->city = $data['city']; $event->uf = $data['uf']; $event->state = $data['uf'];
                    if (! trim((string) $event->address)) throw ValidationException::withMessages(['address' => ['Informe o endereço do evento presencial.']]);
                }
            }

            if (in_array($event->event_format, ['online','hybrid'], true) && ! $event->online_url) throw ValidationException::withMessages(['online_url' => ['Informe a URL de acesso da parte online do evento.']]);
            if ($event->google_maps_url) $event->google_maps_url = trim((string) $event->google_maps_url);
            if (! $event->start_date || ! $event->end_date) return;
            $start = Carbon::parse($event->start_date, config('app.timezone')); $end = Carbon::parse($event->end_date, config('app.timezone'));
            if ($event->isDirty('start_date') && $start->lt(now()->subMinutes(1))) throw ValidationException::withMessages(['start_date' => ['O início do evento não pode ficar no passado.']]);
            if (! $end->gt($start)) throw ValidationException::withMessages(['end_date' => ['O término do evento precisa ser posterior ao início.']]);
        });

        static::saved(function (Event $event) {
            $publishedNow = $event->wasChanged('is_published') && $event->is_published && ! $event->is_cancelled;
            if ($publishedNow) {
                app(EventLineupNotificationService::class)->notifyPublishedEvent($event);
                static::publishFeedActivity($event, 'Publicou o evento '.$event->title.'.');
            }

            if ($event->wasRecentlyCreated || ! $event->is_published) return;
            $materialFields = ['title','description','category','image','event_format','address','address_number','neighborhood','address_complement','formatted_address','google_maps_url','online_platform','online_url','online_instructions','start_date','end_date','venue','city','uf','cep','max_attendees','is_cancelled'];
            $changed = collect(array_keys($event->getChanges()))->intersect($materialFields)->values();
            if ($changed->isEmpty()) return;

            $labels = ['title'=>'nome','description'=>'descrição','category'=>'categoria','image'=>'imagem','event_format'=>'formato','address'=>'endereço','address_number'=>'endereço','neighborhood'=>'endereço','address_complement'=>'endereço','formatted_address'=>'endereço','google_maps_url'=>'localização','online_platform'=>'acesso online','online_url'=>'acesso online','online_instructions'=>'acesso online','start_date'=>'horário','end_date'=>'horário','venue'=>'local','city'=>'cidade','uf'=>'estado','cep'=>'endereço','max_attendees'=>'capacidade','is_cancelled'=>'status'];
            $what = $changed->map(fn ($field) => $labels[$field] ?? $field)->unique()->take(3)->implode(', ');

            static::publishFeedActivity(
                $event,
                $event->is_cancelled
                    ? 'Atualizou o status do evento '.$event->title.'.'
                    : 'Atualizou '.$what.' no evento '.$event->title.'.'
            );

            app(EventAudienceService::class)->notifyAttendees($event, [
                'type'=>'event_updated',
                'title'=>$event->is_cancelled?'Atualização importante do evento':'Seu evento foi atualizado',
                'message'=>$event->is_cancelled?$event->title.' teve uma atualização de status. Confira os detalhes.':$event->title.' teve atualização em '.$what.'. Confira os detalhes.',
                'data'=>['event_id'=>$event->id,'changed_fields'=>$changed->all()],
            ]);
        });
    }

    private static function publishFeedActivity(Event $event, string $body): void
    {
        if (! $event->is_published || $event->is_private) return;

        $actorId = request()->user()?->id;
        if (! $actorId) $actorId = $event->production()->value('user_id');
        if (! $actorId) return;

        $body = trim($body);
        $duplicate = DB::table('event_posts')
            ->where('app_id', $event->app_id)
            ->where('event_id', $event->id)
            ->where('user_id', $actorId)
            ->whereNull('parent_id')
            ->where('body', $body)
            ->where('created_at', '>=', now()->subSeconds(30))
            ->exists();
        if ($duplicate) return;

        DB::table('event_posts')->insert([
            'app_id' => $event->app_id,
            'event_id' => $event->id,
            'user_id' => $actorId,
            'parent_id' => null,
            'body' => $body,
            'status' => 'published',
            'is_pinned' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function temporalStatus(?Carbon $at = null): string
    {
        $at ??= Carbon::now(config('app.timezone', 'America/Sao_Paulo'));
        if ($this->end_date && $at->greaterThanOrEqualTo($this->end_date)) return 'past';
        if ($this->start_date && $at->greaterThanOrEqualTo($this->start_date)) return 'ongoing';
        return 'future';
    }

    public function hasStarted(?Carbon $at = null): bool
    {
        if (! $this->start_date) return false;
        $at ??= Carbon::now(config('app.timezone', 'America/Sao_Paulo'));
        return $at->greaterThanOrEqualTo($this->start_date);
    }

    public function hasEnded(?Carbon $at = null): bool
    {
        if (! $this->end_date) return false;
        $at ??= Carbon::now(config('app.timezone', 'America/Sao_Paulo'));
        return $at->greaterThanOrEqualTo($this->end_date);
    }

    public function isHappeningNow(?Carbon $at = null): bool
    {
        return $this->temporalStatus($at) === 'ongoing';
    }

    public function salesClosed(?Carbon $at = null): bool
    {
        return $this->hasEnded($at) || ! $this->is_published || $this->is_cancelled || $this->is_private;
    }

    public function allowedActions(?Carbon $at = null): array
    {
        $status = $this->temporalStatus($at);
        $public = $this->is_published && ! $this->is_cancelled && ! $this->is_private;
        $salesOpen = $public && $status !== 'past';

        return [
            'purchase' => $salesOpen,
            'claim_courtesy' => $salesOpen,
            'mark_interested' => $public && $status !== 'past',
            'check_in' => $public && $status === 'ongoing',
            'share' => $public,
            'save' => $public,
            'community' => $public,
        ];
    }

    public function getTemporalStatusAttribute(): string { return $this->temporalStatus(); }
    public function getHasStartedAttribute(): bool { return $this->hasStarted(); }
    public function getHasEndedAttribute(): bool { return $this->hasEnded(); }
    public function getIsHappeningNowAttribute(): bool { return $this->isHappeningNow(); }
    public function getSalesClosedAttribute(): bool { return $this->salesClosed(); }
    public function getAllowedActionsAttribute(): array { return $this->allowedActions(); }

    public function application(){return $this->belongsTo(Application::class,'app_id');} public function production(){return $this->belongsTo(Production::class);} public function municipality(){return $this->belongsTo(BrazilianMunicipality::class,'city_id','ibge_code');} public function tickets(){return $this->hasMany(Ticket::class);} public function artists(){return $this->belongsToMany(Artist::class,'event_artist','event_id','artist_id')->withPivot(['participation_type','stage','scheduled_at','description','sort_order','is_headliner'])->withTimestamps();} public function interactions(){return $this->hasMany(Interaction::class,'entity_id')->where('entity_type','event');}
    public function getSegmentsnNamesAttribute(){ $assigned=is_array($this->segments)?$this->segments:[];if($assigned===[])return '<i>Nenhum segmento atribuído</i>';$names=[];$segments=Config::get('segments',[]);foreach($assigned as $key)if(isset($segments[$key]['name']))$names[]=$segments[$key]['name'];return implode(' | ',$names);}
}
