<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Application;
use App\Models\Event;
use App\Models\EventPass;
use App\Services\AppNotificationService;
use App\Services\EventProducerCommunicationService;
use Illuminate\Console\Command;

class EventReminderCommand extends Command
{
    protected $signature = 'platform:remind-events {--application= : Limita o processamento a um slug de aplicação}';
    protected $description = 'Envia lembretes de eventos para qualquer aplicação com a capacidade events habilitada.';

    public function handle(AppNotificationService $notifications, EventProducerCommunicationService $producerCommunications): int
    {
        $requestedSlug = trim((string) $this->option('application'));
        $applications = Application::query()
            ->where('is_active', true)
            ->when($requestedSlug !== '', fn ($query) => $query->where('slug', $requestedSlug))
            ->get();

        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $from = now($timezone)->addMinutes(115);
        $to = now($timezone)->addMinutes(125);

        foreach ($applications as $application) {
            $capabilities = (array) config('platform.applications.' . $application->slug . '.capabilities', []);
            if (! in_array('events', $capabilities, true)) {
                continue;
            }

            Event::query()
                ->where('app_id', $application->id)
                ->where(fn ($query) => $query->where('is_cancelled', false)->orWhereNull('is_cancelled'))
                ->where('start_date', '>', now($timezone))
                ->where('start_date', '<=', now($timezone)->addHours(24))
                ->orderBy('id')
                ->chunkById(100, function ($events) use ($producerCommunications) {
                    foreach ($events as $event) {
                        $producerCommunications->notifyUpcoming($event, 24);
                    }
                });

            $events = Event::query()
                ->where('app_id', $application->id)
                ->where('is_published', true)
                ->where('is_cancelled', false)
                ->whereBetween('start_date', [$from, $to])
                ->get();

            foreach ($events as $event) {
                $holders = EventPass::query()
                    ->where('event_id', $event->id)
                    ->whereNotNull('user_id')
                    ->whereNotIn('status', ['cancelled', 'refunded', 'charged_back'])
                    ->selectRaw('user_id, COUNT(*) as ticket_count')
                    ->groupBy('user_id')
                    ->get();

                foreach ($holders as $holder) {
                    $alreadySent = AppNotification::query()
                        ->where('app_id', $application->id)
                        ->where('user_id', $holder->user_id)
                        ->where('type', 'event_starts_soon')
                        ->where('reference_type', 'event')
                        ->where('reference_id', $event->id)
                        ->exists();

                    if ($alreadySent) {
                        continue;
                    }

                    $count = (int) $holder->ticket_count;
                    $notifications->sendToUser((int) $application->id, (int) $holder->user_id, [
                        'type' => 'event_starts_soon',
                        'title' => 'Seu evento começa em breve',
                        'message' => 'Em breve acontece ' . $event->title . ' e você já tem ' . $count . ' ' . ($count === 1 ? 'ingresso' : 'ingressos') . '.',
                        'reference_type' => 'event',
                        'reference_id' => $event->id,
                        'reference_url' => '/event/' . $event->slug,
                        'data' => [
                            'event_id' => $event->id,
                            'ticket_count' => $count,
                            'starts_at' => optional($event->start_date)->toIso8601String(),
                        ],
                    ]);
                }
            }
        }

        return self::SUCCESS;
    }
}
