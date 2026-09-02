<?php

namespace App\Infrastructure\Products\Cutinapp\Observers;

use App\Models\Event;
use App\Services\CutinappEventAudienceService;
use App\Services\CutinappLineupNotificationService;
use App\Services\CutinappLocationService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class EventObserver
{
    public function saving(Event $event): void
    {
        if ($event->app_slug !== 'cutinapp') return;

        foreach (['event_format','city_id','cep','address_number','neighborhood','address_complement','address_reference','formatted_address','place_id','latitude','longitude','google_maps_url','online_platform','online_url','online_instructions'] as $field) {
            if (request()->exists($field)) $event->setAttribute($field, request()->input($field));
        }

        if (request()->exists('city') && ! request()->exists('city_id')) $event->city_id = null;

        $event->event_format = $event->event_format ?: 'in_person';
        if (! in_array($event->event_format, ['in_person','online','hybrid'], true)) {
            throw ValidationException::withMessages(['event_format' => ['Selecione presencial, online ou híbrido.']]);
        }

        $physical = in_array($event->event_format, ['in_person','hybrid'], true);
        $locationDirty = ! $event->exists || $event->isDirty(['city_id','city','uf','cep','event_format']);
        if ($locationDirty) {
            $service = app(CutinappLocationService::class);
            if ($event->cep) $event->cep = $service->normalizeCep($event->cep);
            if ($physical) {
                $data = ['city_id'=>$event->city_id,'city'=>$event->city,'uf'=>$event->uf];
                $service->applyCanonicalCity($data, true);
                $event->city_id = $data['city_id'];
                $event->city = $data['city'];
                $event->uf = $data['uf'];
                $event->state = $data['uf'];
                if (! trim((string) $event->address)) {
                    throw ValidationException::withMessages(['address' => ['Informe o endereço do evento presencial.']]);
                }
            }
        }

        if (in_array($event->event_format, ['online','hybrid'], true) && ! $event->online_url) {
            throw ValidationException::withMessages(['online_url' => ['Informe a URL de acesso da parte online do evento.']]);
        }
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
    }

    public function saved(Event $event): void
    {
        if ($event->app_slug !== 'cutinapp') return;

        if ($event->wasChanged('is_published') && $event->is_published && ! $event->is_cancelled) {
            app(CutinappLineupNotificationService::class)->notifyPublishedEvent($event);
        }

        if ($event->wasRecentlyCreated || ! $event->is_published) return;

        $materialFields = ['title','description','category','image','event_format','address','address_number','neighborhood','address_complement','formatted_address','google_maps_url','online_platform','online_url','online_instructions','start_date','end_date','venue','city','uf','cep','max_attendees','is_cancelled'];
        $changed = collect(array_keys($event->getChanges()))->intersect($materialFields)->values();
        if ($changed->isEmpty()) return;

        $labels = ['title'=>'nome','description'=>'descrição','category'=>'categoria','image'=>'imagem','event_format'=>'formato','address'=>'endereço','address_number'=>'endereço','neighborhood'=>'endereço','address_complement'=>'endereço','formatted_address'=>'endereço','google_maps_url'=>'localização','online_platform'=>'acesso online','online_url'=>'acesso online','online_instructions'=>'acesso online','start_date'=>'horário','end_date'=>'horário','venue'=>'local','city'=>'cidade','uf'=>'estado','cep'=>'endereço','max_attendees'=>'capacidade','is_cancelled'=>'status'];
        $what = $changed->map(fn ($field) => $labels[$field] ?? $field)->unique()->take(3)->implode(', ');

        app(CutinappEventAudienceService::class)->notifyAttendees($event, [
            'type' => 'event_updated',
            'title' => $event->is_cancelled ? 'Atualização importante do evento' : 'Seu evento foi atualizado',
            'message' => $event->is_cancelled ? $event->title . ' teve uma atualização de status. Confira os detalhes.' : $event->title . ' teve atualização em ' . $what . '. Confira os detalhes.',
            'data' => ['event_id' => $event->id, 'changed_fields' => $changed->all()],
        ]);
    }
}
