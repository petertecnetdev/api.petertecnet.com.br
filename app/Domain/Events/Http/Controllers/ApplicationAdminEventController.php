<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventSeriesService;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ApplicationAdminEventController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EventSeriesService $series,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'production_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Event::query()
            ->where('app_id', $this->context->id())
            ->with('production:id,app_id,name,slug,user_id,app_slug')
            ->withCount('tickets');

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $query->where(fn ($builder) => $builder
                ->where('title', 'like', '%'.$term.'%')
                ->orWhere('city', 'like', '%'.$term.'%')
                ->orWhere('venue', 'like', '%'.$term.'%'));
        }
        if (! empty($data['production_id'])) {
            $query->where('production_id', (int) $data['production_id']);
        }

        return response()->json([
            'events' => $query->orderByDesc('start_date')->paginate($data['per_page'] ?? 100),
        ]);
    }

    public function destroyMany(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:250'],
            'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);

        $appId = $this->context->id();
        $ids = array_values(array_map('intval', $data['ids']));

        $result = DB::transaction(function () use ($appId, $ids): array {
            $events = Event::query()
                ->where('app_id', $appId)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            $foundIds = $events->pluck('id')->map(static fn ($id) => (int) $id);
            $missingIds = collect($ids)->diff($foundIds)->values();

            if ($missingIds->isNotEmpty()) {
                return [
                    'status' => 'missing',
                    'missing_event_ids' => $missingIds->all(),
                ];
            }

            $protectedEventIds = Event::query()
                ->where('app_id', $appId)
                ->whereIn('id', $ids)
                ->whereHas('tickets', static fn ($ticketQuery) => $ticketQuery
                    ->where('app_id', $appId)
                    ->whereHas('passes'))
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->values();

            if ($protectedEventIds->isNotEmpty()) {
                return [
                    'status' => 'protected',
                    'protected_event_ids' => $protectedEventIds->all(),
                ];
            }

            foreach ($events as $event) {
                $event->delete();
            }

            return [
                'status' => 'deleted',
                'deleted_count' => $events->count(),
            ];
        }, 3);

        if ($result['status'] === 'missing') {
            return response()->json([
                'message' => 'Um ou mais eventos selecionados não existem mais. Atualize a lista e tente novamente.',
                'missing_event_ids' => $result['missing_event_ids'],
            ], 404);
        }

        if ($result['status'] === 'protected') {
            return response()->json([
                'message' => 'A exclusão em lote foi cancelada porque há evento(s) com ingressos já emitidos. Nenhum evento foi excluído.',
                'protected_event_ids' => $result['protected_event_ids'],
            ], 409);
        }

        $deletedCount = (int) $result['deleted_count'];

        return response()->json([
            'message' => $deletedCount === 1
                ? '1 evento foi excluído com sucesso.'
                : "{$deletedCount} eventos foram excluídos com sucesso.",
            'deleted_count' => $deletedCount,
            'deleted_event_ids' => $ids,
        ]);
    }

    public function series(Request $request, int $event)
    {
        $source = Event::query()->where('app_id', $this->context->id())->findOrFail($event);

        return response()->json(
            $this->series->create($source, $this->context->id(), $this->context->slug(), $request->all()),
            201,
        );
    }
}
