<?php

namespace App\Services;

use App\Mail\EventProducerUpdatedMail;
use App\Models\Application;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EventProducerCommunicationService
{
    public function notify(Event $event, string $action = 'updated', array $changedFields = []): void
    {
        $production = $event->production_id
            ? Establishment::query()->find($event->production_id)
            : null;

        if (! $production || ! $production->user_id) {
            Log::warning('Evento sem produtor proprietário para comunicação.', [
                'event_id' => $event->id,
                'production_id' => $event->production_id,
            ]);

            return;
        }

        $owner = User::query()->find($production->user_id);
        if (! $owner) {
            Log::warning('Produtor proprietário do evento não foi encontrado.', [
                'event_id' => $event->id,
                'production_id' => $production->id,
                'user_id' => $production->user_id,
            ]);

            return;
        }

        $action = in_array($action, ['created', 'activated', 'reactivated', 'deactivated', 'updated'], true)
            ? $action
            : 'updated';

        $changedFields = array_values(array_unique(array_filter($changedFields)));
        $changedLabels = $this->fieldLabels($changedFields);

        $appId = (int) ($event->app_id ?: $production->app_id);
        $application = $appId > 0 ? Application::query()->find($appId) : null;
        $appName = $this->applicationName($application, $event);
        $productionName = $production->fantasy ?: $production->name ?: 'sua produção';
        $appUrl = $this->applicationUrl($application, $event);
        $eventUrl = $this->eventPublicUrl($appUrl, $event);
        $eventManagementUrl = $appUrl.'/event/edit/'.$event->id;
        [$title, $message] = $this->copyFor($event, $action, $changedLabels, $productionName, $appName);

        if ($appId > 0) {
            try {
                app(AppNotificationService::class)->sendToUser($appId, (int) $owner->id, [
                    'type' => 'producer_event_'.$action,
                    'title' => $title,
                    'message' => $message,
                    'reference_type' => 'event',
                    'reference_id' => $event->id,
                    'reference_url' => '/event/edit/'.$event->id,
                    'data' => [
                        'event_id' => (int) $event->id,
                        'production_id' => (int) $production->id,
                        'action' => $action,
                        'changed_fields' => $changedFields,
                        'app_url' => $appUrl,
                        'event_url' => $eventUrl,
                        'event_management_url' => $eventManagementUrl,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::error('Falha ao criar notificação do evento para o produtor.', [
                    'event_id' => $event->id,
                    'user_id' => $owner->id,
                    'action' => $action,
                    'message' => $e->getMessage(),
                ]);
            }
        } else {
            Log::warning('Evento sem aplicação definida para notificação interna.', [
                'event_id' => $event->id,
                'production_id' => $production->id,
            ]);
        }

        if (! $owner->email) {
            Log::warning('Produtor do evento sem e-mail cadastrado.', [
                'event_id' => $event->id,
                'user_id' => $owner->id,
            ]);

            return;
        }

        try {
            Mail::to($owner->email)->send(new EventProducerUpdatedMail(
                $owner,
                $event,
                $production,
                $action,
                $changedLabels,
                $title,
                $message,
                $appUrl,
                $eventUrl,
                $eventManagementUrl,
                $appName
            ));
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar e-mail de atualização do evento ao produtor.', [
                'event_id' => $event->id,
                'user_id' => $owner->id,
                'action' => $action,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function copyFor(Event $event, string $action, array $changedLabels, string $productionName, string $appName): array
    {
        $eventName = trim((string) $event->title) ?: 'Evento #'.$event->id;
        $changed = $changedLabels === []
            ? null
            : implode(', ', array_slice($changedLabels, 0, 5));

        return match ($action) {
            'created' => [
                'Novo evento criado: '.$eventName,
                'O evento "'.$eventName.'" foi criado para '.$productionName.'. Confira os dados e continue a gestão pela '.$appName.'.',
            ],
            'activated' => [
                'Evento ativado: '.$eventName,
                'O evento "'.$eventName.'" foi ativado/publicado para '.$productionName.'. Confira os detalhes e acompanhe a operação pela '.$appName.'.',
            ],
            'reactivated' => [
                'Evento reativado: '.$eventName,
                'O evento "'.$eventName.'" voltou à atividade para '.$productionName.'. Confira os detalhes atuais na '.$appName.'.',
            ],
            'deactivated' => [
                'Status do evento alterado: '.$eventName,
                'O evento "'.$eventName.'" teve seu status alterado para '.$productionName.'. Abra a '.$appName.' para conferir a situação atual.',
            ],
            default => [
                'Evento atualizado: '.$eventName,
                $changed
                    ? 'O evento "'.$eventName.'" foi atualizado na '.$appName.'. Alterações: '.$changed.'.'
                    : 'O evento "'.$eventName.'" foi atualizado na '.$appName.'. Confira os detalhes atuais.',
            ],
        };
    }

    private function applicationName(?Application $application, Event $event): string
    {
        $identity = Str::lower(implode(' ', array_filter([
            $application?->name,
            $application?->slug,
            $event->app_slug,
        ])));

        if (Str::contains($identity, 'cutin')) {
            return 'Cutinapp';
        }

        return trim((string) $application?->name) ?: 'Peter Tecnet';
    }

    private function applicationUrl(?Application $application, Event $event): string
    {
        $baseUrl = rtrim(trim((string) $application?->url), '/');

        if (filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return $baseUrl;
        }

        $identity = Str::lower(implode(' ', array_filter([
            $application?->slug,
            $event->app_slug,
        ])));

        return Str::contains($identity, 'cutin')
            ? 'https://cutinapp.petertecnet.com.br'
            : 'https://petertecnet.com.br';
    }

    private function eventPublicUrl(string $appUrl, Event $event): string
    {
        $slug = trim((string) $event->slug);

        if ($slug !== '') {
            return $appUrl.'/event/'.rawurlencode($slug);
        }

        return $appUrl.'/event/edit/'.$event->id;
    }

    private function fieldLabels(array $fields): array
    {
        $labels = [
            'title' => 'nome',
            'description' => 'descrição',
            'category' => 'categoria',
            'image' => 'imagem',
            'event_format' => 'formato',
            'address' => 'endereço',
            'address_number' => 'número do endereço',
            'neighborhood' => 'bairro',
            'address_complement' => 'complemento',
            'address_reference' => 'referência do endereço',
            'formatted_address' => 'endereço completo',
            'google_maps_url' => 'localização no mapa',
            'online_platform' => 'plataforma online',
            'online_url' => 'link online',
            'online_instructions' => 'instruções de acesso online',
            'start_date' => 'data e hora de início',
            'end_date' => 'data e hora de término',
            'venue' => 'local',
            'city' => 'cidade',
            'city_id' => 'cidade',
            'uf' => 'estado',
            'state' => 'estado',
            'country' => 'país',
            'cep' => 'CEP',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'is_featured' => 'destaque',
            'is_published' => 'publicação',
            'is_approved' => 'aprovação',
            'is_cancelled' => 'status',
            'max_attendees' => 'capacidade',
            'remaining_tickets' => 'ingressos restantes',
            'extra_info' => 'informações extras',
            'agenda' => 'agenda',
            'menu' => 'menu',
            'additional_info' => 'informações adicionais',
            'facebook_url' => 'Facebook',
            'twitter_url' => 'X/Twitter',
            'instagram_url' => 'Instagram',
            'youtube_url' => 'YouTube',
            'contact_email' => 'e-mail de contato',
            'contact_phone' => 'telefone de contato',
            'website' => 'site',
            'registration_link' => 'link de inscrição',
            'organizer_name' => 'organizador',
            'organizer_email' => 'e-mail do organizador',
            'organizer_phone' => 'telefone do organizador',
            'organizer_description' => 'descrição do organizador',
            'speaker_list' => 'palestrantes',
            'sponsor_list' => 'patrocinadores',
            'partners' => 'parceiros',
            'is_private' => 'privacidade',
            'requires_approval' => 'aprovação de participantes',
            'approval_message' => 'mensagem de aprovação',
            'segments' => 'segmentos',
            'establishment_name' => 'estabelecimento',
            'production_id' => 'produção responsável',
        ];

        return collect($fields)
            ->reject(fn ($field) => in_array($field, ['created_at', 'updated_at'], true))
            ->map(fn ($field) => $labels[$field] ?? Str::lower(Str::headline((string) $field)))
            ->unique()
            ->values()
            ->all();
    }
}
