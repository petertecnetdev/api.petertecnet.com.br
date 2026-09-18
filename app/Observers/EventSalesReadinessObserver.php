<?php

namespace App\Observers;

use App\Models\Event;
use App\Models\Ticket;
use App\Services\OrganizationSalesReadinessService;
use App\Support\ApplicationContext;
use Illuminate\Validation\ValidationException;

final class EventSalesReadinessObserver
{
    public function saving(Event $event): void
    {
        if (! $event->exists || ! $event->isDirty('is_published') || ! $event->is_published) {
            return;
        }

        $context = app(ApplicationContext::class);
        if (! $context->has() || $context->id() !== (int) $event->app_id) {
            return;
        }

        $hasPaidTicket = Ticket::query()
            ->where('app_id', $event->app_id)
            ->where('event_id', $event->id)
            ->where('price', '>', 0)
            ->where('quantity', '>', 0)
            ->exists();

        if (! $hasPaidTicket) {
            return;
        }

        $readiness = app(OrganizationSalesReadinessService::class)->status((int) $event->production_id);

        if (! $readiness['ready']) {
            throw ValidationException::withMessages([
                'sales_readiness' => [$readiness['message']],
            ]);
        }
    }
}
