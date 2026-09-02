<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Application;
use App\Models\Event;
use App\Models\EventPass;
use App\Services\AppNotificationService;
use Illuminate\Console\Command;

class CutinappEventReminderCommand extends Command
{
    protected $signature = 'cutinapp:remind-events';
    protected $description = 'Envia lembretes dos eventos da Cutinapp aproximadamente duas horas antes do início.';

    public function handle(AppNotificationService $notifications): int
    {
        $app = Application::query()->where('slug', 'cutinapp')->where('is_active', true)->first();
        if (! $app) return self::SUCCESS;

        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $from = now($timezone)->addMinutes(115);
        $to = now($timezone)->addMinutes(125);

        $events = Event::query()
            ->where('app_id', $app->id)
            ->where('app_slug', 'cutinapp')
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
                    ->where('app_id', $app->id)
                    ->where('user_id', $holder->user_id)
                    ->where('type', 'event_starts_soon')
                    ->where('reference_type', 'event')
                    ->where('reference_id', $event->id)
                    ->exists();

                if ($alreadySent) continue;

                $count = (int) $holder->ticket_count;
                $notifications->sendToUser((int) $app->id, (int) $holder->user_id, [
                    'type' => 'event_starts_soon',
                    'title' => 'Seu evento começa em breve',
                    'message' => 'Em breve acontece ' . $event->title . ' e você já tem ' . $count . ' ' . ($count === 1 ? 'ingresso' : 'ingressos') . '. Clique aqui para ver o evento.',
                    'reference_type' => 'event',
                    'reference_id' => $event->id,
                    'reference_url' => '/event/' . $event->slug,
                    'data' => ['event_id' => $event->id, 'ticket_count' => $count, 'starts_at' => optional($event->start_date)->toIso8601String()],
                ]);
            }
        }

        return self::SUCCESS;
    }
}
