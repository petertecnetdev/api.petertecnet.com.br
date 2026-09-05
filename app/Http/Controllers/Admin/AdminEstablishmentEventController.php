<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Events\Services\EventDuplicationService;
use App\Http\Controllers\Controller;
use App\Models\EcosystemAuditLog;
use App\Models\Establishment;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdminEstablishmentEventController extends Controller
{
    public function __construct(private readonly EventDuplicationService $duplicator) {}

    public function index(Request $request, Establishment $establishment): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
        ]);

        $appId = (int) $data['app_id'];
        $this->assertApplicationLinked($establishment, $appId);

        $events = Event::query()
            ->where('production_id', $establishment->id)
            ->where('app_id', $appId)
            ->withCount(['tickets', 'artists'])
            ->orderByDesc('start_date')
            ->limit(100)
            ->get([
                'id', 'app_id', 'app_slug', 'production_id', 'title', 'slug', 'start_date', 'end_date',
                'venue', 'city', 'uf', 'is_published', 'is_cancelled', 'image',
            ]);

        return response()->json([
            'establishment' => [
                'id' => $establishment->id,
                'name' => $establishment->fantasy ?: $establishment->name,
                'user_id' => $establishment->user_id,
            ],
            'events' => $events,
        ]);
    }

    public function duplicate(Request $request, Establishment $establishment, Event $event): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ], [
            'date.required' => 'Informe a nova data do evento.',
            'date.date_format' => 'Informe uma data válida.',
        ]);

        $appId = (int) $data['app_id'];
        $this->assertApplicationLinked($establishment, $appId);

        if ((int) $event->production_id !== (int) $establishment->id || (int) $event->app_id !== $appId) {
            throw ValidationException::withMessages([
                'event' => ['O evento informado não pertence a este estabelecimento e aplicação.'],
            ]);
        }

        $duplicate = $this->duplicator->duplicate($event, $data['date'], $appId, $event->app_slug);

        EcosystemAuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'event.duplicated_from_admin_center',
            'entity_type' => Event::class,
            'entity_id' => $duplicate->id,
            'before' => ['source_event_id' => $event->id],
            'after' => [
                'duplicate_event_id' => $duplicate->id,
                'source_event_id' => $event->id,
                'production_id' => $establishment->id,
                'app_id' => $appId,
                'new_date' => $data['date'],
            ],
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);

        return response()->json([
            'message' => 'Evento duplicado como rascunho pelo Admin Center.',
            'event' => $duplicate,
            'copied' => [
                'tickets' => $duplicate->tickets_count,
                'artists' => $duplicate->artists->count(),
            ],
        ], 201);
    }

    private function assertApplicationLinked(Establishment $establishment, int $appId): void
    {
        $linked = (int) $establishment->app_id === $appId
            || $establishment->applications()->where('applications.id', $appId)->exists();

        if (! $linked) {
            throw ValidationException::withMessages([
                'app_id' => ['A aplicação informada não está vinculada a este estabelecimento.'],
            ]);
        }
    }

    private function authorizeAccess(Request $request): void
    {
        $email = strtolower(trim((string) $request->user()?->email));
        abort_unless($email === 'petertecnet@gmail.com', 403, 'Apenas o administrador principal pode gerenciar eventos por este painel.');
    }
}
