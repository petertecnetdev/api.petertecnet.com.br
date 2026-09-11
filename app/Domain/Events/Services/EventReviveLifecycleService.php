<?php

namespace App\Domain\Events\Services;

use App\Models\AppNotification;
use App\Models\Event;
use App\Models\EventPass;
use App\Services\AppNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EventReviveLifecycleService
{
    private const INVALID_PASS_STATUSES = ['cancelled', 'refunded', 'charged_back'];

    public function __construct(
        private readonly AppNotificationService $notifications,
    ) {}

    public function dispatchPostEventPrompts(): array
    {
        $events = Event::query()
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->whereBetween('end_date', [now()->subHours(6), now()->subMinutes(20)])
            ->orderBy('id')
            ->limit(250)
            ->get(['id', 'app_id', 'title', 'slug', 'end_date']);

        $sent = 0;
        foreach ($events as $event) {
            $users = $this->participantIds($event);
            foreach ($users as $userId) {
                if ($this->alreadyNotified((int) $event->app_id, $userId, 'event_revive_prompt', (int) $event->id)) {
                    continue;
                }

                $this->notifications->sendToUser((int) $event->app_id, $userId, [
                    'type' => 'event_revive_prompt',
                    'title' => 'Como foi '.$event->title.'?',
                    'message' => 'O evento terminou. Compartilhe seus momentos e conte para quem esteve lá como foi sua experiência.',
                    'reference_type' => 'event',
                    'reference_id' => $event->id,
                    'reference_url' => '/event/'.$event->slug.'#reviva',
                    'data' => [
                        'event_id' => $event->id,
                        'action' => 'review_and_share',
                    ],
                ]);
                $sent++;
            }
        }

        return ['events' => $events->count(), 'notifications_sent' => $sent];
    }

    public function dispatchNextEditionNotifications(): array
    {
        if (! Schema::hasTable('event_revive_preferences')) {
            return ['preferences' => 0, 'notifications_sent' => 0];
        }

        $rows = DB::table('event_revive_preferences as rp')
            ->join('events as source', 'source.id', '=', 'rp.event_id')
            ->where('rp.notify_next', true)
            ->where('source.end_date', '<=', now())
            ->where('source.is_cancelled', false)
            ->select([
                'rp.app_id',
                'rp.event_id as source_event_id',
                'rp.user_id',
                'source.production_id',
                'source.title as source_title',
            ])
            ->orderBy('rp.id')
            ->limit(3000)
            ->get();

        $sent = 0;
        foreach ($rows as $row) {
            $next = Event::query()
                ->where('app_id', $row->app_id)
                ->where('production_id', $row->production_id)
                ->where('id', '!=', $row->source_event_id)
                ->where('is_published', true)
                ->where('is_cancelled', false)
                ->where('start_date', '>', now())
                ->orderBy('start_date')
                ->first(['id', 'title', 'slug', 'start_date']);

            if (! $next) {
                continue;
            }

            if ($this->alreadyNotified((int) $row->app_id, (int) $row->user_id, 'event_revive_next_edition', (int) $next->id)) {
                continue;
            }

            $this->notifications->sendToUser((int) $row->app_id, (int) $row->user_id, [
                'type' => 'event_revive_next_edition',
                'title' => 'A próxima edição chegou',
                'message' => $next->title.' já está disponível. Você participou da edição anterior; não fique de fora desta.',
                'reference_type' => 'event',
                'reference_id' => $next->id,
                'reference_url' => '/event/'.$next->slug.'?source_event_id='.$row->source_event_id.'&conversion_source=post_event',
                'data' => [
                    'source_event_id' => (int) $row->source_event_id,
                    'target_event_id' => (int) $next->id,
                    'conversion_source' => 'post_event',
                ],
            ]);
            $sent++;
        }

        return ['preferences' => $rows->count(), 'notifications_sent' => $sent];
    }

    public function dispatchOneYearMemories(): array
    {
        $target = now()->subYear();
        $events = Event::query()
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->whereDate('end_date', $target->toDateString())
            ->orderBy('id')
            ->limit(250)
            ->get(['id', 'app_id', 'title', 'slug']);

        $sent = 0;
        foreach ($events as $event) {
            foreach ($this->participantIds($event) as $userId) {
                if ($this->alreadyNotified((int) $event->app_id, $userId, 'event_revive_memory_1y', (int) $event->id)) {
                    continue;
                }

                $this->notifications->sendToUser((int) $event->app_id, $userId, [
                    'type' => 'event_revive_memory_1y',
                    'title' => 'Há 1 ano você estava aqui',
                    'message' => 'Reviva os momentos de '.$event->title.' e veja o que a galera compartilhou.',
                    'reference_type' => 'event',
                    'reference_id' => $event->id,
                    'reference_url' => '/event/'.$event->slug.'#reviva',
                    'data' => ['event_id' => $event->id, 'memory_years' => 1],
                ]);
                $sent++;
            }
        }

        return ['events' => $events->count(), 'notifications_sent' => $sent];
    }

    private function participantIds(Event $event): array
    {
        return EventPass::query()
            ->where('event_id', $event->id)
            ->whereNotNull('user_id')
            ->whereNotIn('status', self::INVALID_PASS_STATUSES)
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();
    }

    private function alreadyNotified(int $appId, int $userId, string $type, int $referenceId): bool
    {
        return AppNotification::query()
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('reference_type', 'event')
            ->where('reference_id', $referenceId)
            ->exists();
    }
}
