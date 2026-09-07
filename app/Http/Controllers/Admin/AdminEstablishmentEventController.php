<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Events\Services\AdminEstablishmentEventService;
use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AdminEstablishmentEventController extends Controller
{
    public function __construct(private readonly AdminEstablishmentEventService $events) {}

    public function index(Request $request, Establishment $establishment): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
        ]);

        return response()->json(
            $this->events->index($establishment, (int) $data['app_id'])
        );
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

        return response()->json(
            $this->events->duplicate(
                $establishment,
                $event,
                (int) $data['app_id'],
                $data['date'],
                $request->user()?->id,
                $request->ip(),
                $request->userAgent(),
            ),
            201,
        );
    }

    public function bulkDestroy(Request $request, Establishment $establishment)
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
            'event_ids' => ['required', 'array', 'min:1', 'max:500'],
            'event_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ], [
            'event_ids.required' => 'Selecione pelo menos um evento para excluir.',
            'event_ids.min' => 'Selecione pelo menos um evento para excluir.',
            'event_ids.max' => 'Exclua no máximo 500 eventos por operação.',
        ]);

        $appId = (int) $data['app_id'];
        $eventIds = array_map('intval', $data['event_ids']);
        $acceptsProgressStream = str_contains(
            strtolower((string) $request->header('Accept')),
            'application/x-ndjson'
        );

        if ($acceptsProgressStream) {
            return response()->stream(function () use ($request, $establishment, $appId, $eventIds): void {
                @set_time_limit(0);

                try {
                    $result = $this->events->bulkDelete(
                        $establishment,
                        $appId,
                        $eventIds,
                        $request->user()?->id,
                        $request->ip(),
                        $request->userAgent(),
                        function (array $progress): void {
                            $this->emitFrame(['type' => 'progress'] + $progress);
                        },
                    );

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

        return response()->json(
            $this->events->bulkDelete(
                $establishment,
                $appId,
                $eventIds,
                $request->user()?->id,
                $request->ip(),
                $request->userAgent(),
            )
        );
    }

    private function authorizeAccess(Request $request): void
    {
        $email = strtolower(trim((string) $request->user()?->email));
        abort_unless($email === 'petertecnet@gmail.com', 403, 'Apenas o administrador principal pode gerenciar eventos por este painel.');
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

        return 'Não foi possível concluir a exclusão em massa. Eventos já excluídos permanecem removidos; os demais podem ser tentados novamente.';
    }
}
