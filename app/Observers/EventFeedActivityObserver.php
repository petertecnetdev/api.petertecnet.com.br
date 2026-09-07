<?php

namespace App\Observers;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class EventFeedActivityObserver
{
    private const IGNORED_FIELDS = [
        'created_at',
        'updated_at',
        'rating',
        'reviews',
        'remaining_tickets',
        'is_approved',
        'is_featured',
        'is_published',
        'slug',
    ];

    public function updated(Event $event): void
    {
        $changedFields = array_values(array_diff(
            array_keys($event->getChanges()),
            self::IGNORED_FIELDS
        ));

        if ($changedFields === []) {
            return;
        }

        $eventId = (int) $event->id;

        DB::afterCommit(function () use ($eventId, $changedFields): void {
            try {
                $fresh = Event::query()->with('production')->find($eventId);

                if (! $fresh || ! $this->isVisibleInPublicFeed($fresh)) {
                    return;
                }

                $this->publish($fresh, $changedFields);
            } catch (\Throwable $e) {
                Log::error('Falha ao publicar atualização do evento no feed.', [
                    'event_id' => $eventId,
                    'changed_fields' => $changedFields,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }

    private function publish(Event $event, array $changedFields): void
    {
        $production = $event->production;
        $ownerId = (int) ($production?->user_id ?? 0);
        $appId = (int) $event->app_id;

        if ($ownerId <= 0 || $appId <= 0) {
            return;
        }

        $requestKey = 'event_feed_activity_'.$appId.'_'.$event->id;
        $state = null;

        if (app()->bound('request')) {
            $state = request()->attributes->get($requestKey);
        }

        $allChangedFields = array_values(array_unique(array_merge(
            is_array($state) ? ($state['fields'] ?? []) : [],
            $changedFields
        )));

        $body = $this->body($event, $allChangedFields);
        $postId = is_array($state) ? (int) ($state['post_id'] ?? 0) : 0;
        $now = now();

        if ($postId > 0) {
            $updated = DB::table('event_posts')
                ->where('id', $postId)
                ->where('app_id', $appId)
                ->where('event_id', $event->id)
                ->update([
                    'body' => $body,
                    'updated_at' => $now,
                ]);

            if ($updated === 0) {
                $postId = 0;
            }
        }

        if ($postId <= 0) {
            $postId = (int) DB::table('event_posts')->insertGetId([
                'app_id' => $appId,
                'event_id' => (int) $event->id,
                'user_id' => $ownerId,
                'parent_id' => null,
                'body' => $body,
                'status' => 'published',
                'is_pinned' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (app()->bound('request')) {
            request()->attributes->set($requestKey, [
                'post_id' => $postId,
                'fields' => $allChangedFields,
            ]);
        }
    }

    private function isVisibleInPublicFeed(Event $event): bool
    {
        return (bool) $event->is_published
            && ! (bool) $event->is_cancelled
            && ! (bool) $event->is_private;
    }

    private function body(Event $event, array $changedFields): string
    {
        $eventName = trim((string) $event->title) ?: 'Evento #'.$event->id;
        $labels = collect($changedFields)
            ->reject(fn ($field) => in_array($field, self::IGNORED_FIELDS, true))
            ->map(fn ($field) => $this->fieldLabel((string) $field))
            ->unique()
            ->values()
            ->all();

        if ($labels === []) {
            return 'Atualização do evento • '.$eventName."\nConfira os detalhes mais recentes do evento.";
        }

        $visible = array_slice($labels, 0, 5);
        $summary = implode(', ', $visible);
        $remaining = count($labels) - count($visible);

        if ($remaining > 0) {
            $summary .= ' e mais '.$remaining.' '.($remaining === 1 ? 'alteração' : 'alterações');
        }

        return 'Atualização do evento • '.$eventName."\nAlterações: ".$summary.'.';
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'title' => 'nome',
            'description' => 'descrição',
            'category' => 'categoria',
            'image' => 'imagem do evento',
            'event_format' => 'formato',
            'address', 'formatted_address' => 'endereço',
            'google_maps_url', 'latitude', 'longitude' => 'localização',
            'start_date' => 'data ou horário de início',
            'end_date' => 'data ou horário de término',
            'venue' => 'local',
            'city' => 'cidade',
            'uf', 'state' => 'estado',
            'cep' => 'CEP',
            'max_attendees' => 'capacidade',
            'contact_email', 'contact_phone' => 'contato',
            'is_private' => 'privacidade',
            'online_url' => 'link online',
            'production_id' => 'produção responsável',
            default => Str::lower(Str::headline($field)),
        };
    }
}
