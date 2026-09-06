<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Events\Services\EventSeriesService;
use App\Http\Controllers\Controller;
use App\Models\EcosystemAuditLog;
use App\Models\Establishment;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdminEventSeriesController extends Controller
{
    public function __construct(private readonly EventSeriesService $series) {}

    public function __invoke(Request $request, Establishment $establishment, Event $event)
    {
        $email = strtolower(trim((string) $request->user()?->email));
        abort_unless($email === 'petertecnet@gmail.com', 403, 'Apenas o administrador principal pode criar agendas de eventos por este painel.');

        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
        ]);
        $appId = (int) $data['app_id'];

        $linked = (int) $establishment->app_id === $appId
            || $establishment->applications()->where('applications.id', $appId)->exists();
        if (! $linked) {
            throw ValidationException::withMessages(['app_id' => ['A aplicação informada não está vinculada a este estabelecimento.']]);
        }
        if ((int) $event->production_id !== (int) $establishment->id || (int) $event->app_id !== $appId) {
            throw ValidationException::withMessages(['event' => ['O evento informado não pertence a este estabelecimento e aplicação.']]);
        }

        $result = $this->series->create($event, $appId, $event->app_slug, $request->all());

        EcosystemAuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'event.series_created_from_admin_center',
            'entity_type' => Event::class,
            'entity_id' => $event->id,
            'before' => ['source_event_id' => $event->id],
            'after' => [
                'source_event_id' => $event->id,
                'production_id' => $establishment->id,
                'app_id' => $appId,
                'mode' => $result['mode'],
                'created_count' => $result['created_count'],
                'existing_count' => $result['existing_count'],
                'target_dates' => $result['target_dates'],
            ],
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);

        return response()->json($result, 201);
    }
}
