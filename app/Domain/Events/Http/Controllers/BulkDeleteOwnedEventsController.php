<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BulkDeleteOwnedEventsController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function __invoke(Request $request)
    {
        $appId = $this->context->id();
        $userId = (int) $request->user()->id;

        $ownedEvents = static fn () => Event::query()
            ->where('app_id', $appId)
            ->whereHas('production', static fn ($query) => $query
                ->where('app_id', $appId)
                ->where('user_id', $userId));

        $protectedEventIds = $ownedEvents()
            ->whereHas('tickets', static fn ($ticketQuery) => $ticketQuery
                ->where('app_id', $appId)
                ->whereHas('passes'))
            ->pluck('id');

        if ($protectedEventIds->isNotEmpty()) {
            return response()->json([
                'message' => 'Não foi possível excluir todos os eventos porque existem eventos com ingressos já emitidos. Nenhum evento foi excluído.',
                'protected_event_ids' => $protectedEventIds->values(),
            ], 409);
        }

        $deletedCount = DB::transaction(function () use ($ownedEvents): int {
            $events = $ownedEvents()->lockForUpdate()->get();

            foreach ($events as $event) {
                $event->delete();
            }

            return $events->count();
        }, 3);

        return response()->json([
            'message' => $deletedCount === 1
                ? '1 evento foi excluído com sucesso.'
                : "{$deletedCount} eventos foram excluídos com sucesso.",
            'deleted_count' => $deletedCount,
        ]);
    }
}
