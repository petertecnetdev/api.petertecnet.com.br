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
use Throwable;

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

        $acceptsProgressStream = str_contains(
            strtolower((string) $request->header('Accept')),
            'application/x-ndjson'
        );

        if ($acceptsProgressStream) {
            return response()->stream(function () use ($request, $establishment, $event, $appId): void {
                try {
                    $result = $this->series->create(
                        $event,
                        $appId,
                        $event->app_slug,
                        $request->all(),
                        function (array $progress): void {
                            $this->emitFrame(['type' => 'progress'] + $progress);
                        }
                    );

                    $this->audit($request, $establishment, $event, $appId, $result);
                    $this->emitFrame([
                        'type' => 'complete',
                        'result' => $result,
                    ]);
                } catch (Throwable $exception) {
                    report($exception);
                    $this->emitFrame([
                        'type' => 'error',
                        'message' => $this->streamErrorMessage($exception),
                    ]);
                }
            }, 200, [
                'Content-Type' => 'application/x-ndjson; charset=UTF-8',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $result = $this->series->create($event, $appId, $event->app_slug, $request->all());
        $this->audit($request, $establishment, $event, $appId, $result);

        return response()->json($result, 201);
    }

    private function audit(Request $request, Establishment $establishment, Event $event, int $appId, array $result): void
    {
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
    }

    private function emitFrame(array $frame): void
    {
        echo json_encode($frame, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    private function streamErrorMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            $message = collect($exception->errors())->flatten()->first();
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return 'Não foi possível concluir a criação da agenda. As ocorrências já processadas permanecem salvas e uma nova tentativa reutilizará as duplicidades existentes.';
    }
}
