<?php

namespace App\Observers;

use App\Domain\People\Services\ArtistInvitationWorkflowService;
use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ArtistInvitationEventObserver
{
    public function updated(Event $event): void
    {
        $changed = array_values(array_diff(array_keys($event->getChanges()), ['created_at','updated_at']));
        if ($changed === []) return;

        $relevant = array_values(array_intersect($changed, [
            'is_cancelled','start_date','end_date','venue','address','address_number',
            'neighborhood','city','uf','formatted_address',
        ]));
        if ($relevant === []) return;

        $eventId = (int) $event->id;

        DB::afterCommit(function () use ($eventId, $relevant): void {
            try {
                $fresh = Event::query()->with('production')->find($eventId);
                if (! $fresh) return;

                app(ArtistInvitationWorkflowService::class)->cancelForEvent($fresh, $relevant);
            } catch (\Throwable $exception) {
                Log::error('Falha ao sincronizar convites artísticos após atualização de evento.', [
                    'event_id' => $eventId,
                    'changed_fields' => $relevant,
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }
}
