<?php

namespace App\Observers;

use App\Models\Event;
use App\Services\EventProducerCommunicationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EventProducerNotificationObserver
{
    public function created(Event $event): void
    {
        $this->afterCommit($event, 'created', []);
    }

    public function updated(Event $event): void
    {
        $changedFields = array_values(array_diff(
            array_keys($event->getChanges()),
            ['updated_at']
        ));

        if ($changedFields === []) {
            return;
        }

        $this->afterCommit($event, $this->resolveAction($event), $changedFields);
    }

    private function resolveAction(Event $event): string
    {
        if (
            $event->wasChanged('is_cancelled')
            && ! $event->is_cancelled
            && (bool) $event->getOriginal('is_cancelled')
        ) {
            return 'reactivated';
        }

        if (
            ($event->wasChanged('is_published') && $event->is_published)
            || ($event->wasChanged('is_approved') && $event->is_approved)
        ) {
            return 'activated';
        }

        if (
            ($event->wasChanged('is_cancelled') && $event->is_cancelled)
            || ($event->wasChanged('is_published') && ! $event->is_published)
        ) {
            return 'deactivated';
        }

        return 'updated';
    }

    private function afterCommit(Event $event, string $action, array $changedFields): void
    {
        $eventId = (int) $event->id;

        DB::afterCommit(function () use ($eventId, $action, $changedFields) {
            try {
                $fresh = Event::query()->find($eventId);

                if (! $fresh) {
                    return;
                }

                app(EventProducerCommunicationService::class)
                    ->notify($fresh, $action, $changedFields);
            } catch (\Throwable $e) {
                Log::error('Falha ao comunicar atualização de evento ao produtor.', [
                    'event_id' => $eventId,
                    'action' => $action,
                    'changed_fields' => $changedFields,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }
}
