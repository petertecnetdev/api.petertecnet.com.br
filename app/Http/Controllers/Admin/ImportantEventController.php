<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportantEvent;
use App\Models\ImportantEventRead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportantEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $perPage = min(100, max(10, (int) $request->integer('per_page', 30)));
        $query = $this->filteredQuery($request)
            ->with(['application:id,name,slug,logo'])
            ->with(['reads' => fn ($q) => $q->where('user_id', $userId)->select('id', 'important_event_id', 'user_id', 'read_at')]);

        if ($request->boolean('unread')) {
            $query->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId));
        }

        $events = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage);
        $events->through(function (ImportantEvent $event) {
            $read = $event->reads->first();
            $data = $event->toArray();
            unset($data['reads']);
            $data['read_at'] = $read?->read_at?->toIso8601String();
            $data['is_read'] = (bool) $read;
            return $data;
        });

        $base = $this->filteredQuery($request);
        return response()->json([
            'events' => $events,
            'summary' => [
                'total' => (clone $base)->count(),
                'unread' => (clone $base)->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId))->count(),
                'critical' => (clone $base)->where('severity', 'critical')->count(),
                'warning' => (clone $base)->whereIn('severity', ['warning', 'attention'])->count(),
                'success' => (clone $base)->where('severity', 'success')->count(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        return response()->json(['unread' => ImportantEvent::query()->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId))->count()]);
    }

    public function markRead(Request $request, ImportantEvent $importantEvent): JsonResponse
    {
        $read = ImportantEventRead::query()->updateOrCreate(
            ['important_event_id' => $importantEvent->id, 'user_id' => (int) $request->user()->id],
            ['read_at' => now()]
        );
        return response()->json(['success' => true, 'read_at' => $read->read_at?->toIso8601String()]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $now = now();
        $updated = 0;
        ImportantEvent::query()->select('id')->orderBy('id')->chunkById(500, function ($events) use ($userId, $now, &$updated) {
            $rows = $events->map(fn ($event) => [
                'important_event_id' => (int) $event->id,
                'user_id' => $userId,
                'read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            if ($rows === []) return;
            DB::table('important_event_reads')->upsert($rows, ['important_event_id', 'user_id'], ['read_at', 'updated_at']);
            $updated += count($rows);
        });
        return response()->json(['success' => true, 'marked' => $updated]);
    }

    private function filteredQuery(Request $request)
    {
        $query = ImportantEvent::query();
        if ($request->filled('app_id')) $query->where('app_id', (int) $request->integer('app_id'));
        if ($request->filled('type')) $query->where('type', (string) $request->string('type'));
        if ($request->filled('severity')) $query->where('severity', (string) $request->string('severity'));
        if ($request->filled('search')) {
            $term = trim((string) $request->string('search'));
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', '%'.$term.'%')
                    ->orWhere('message', 'like', '%'.$term.'%')
                    ->orWhere('type', 'like', '%'.$term.'%')
                    ->orWhere('reference_id', 'like', '%'.$term.'%');
            });
        }
        return $query;
    }
}
