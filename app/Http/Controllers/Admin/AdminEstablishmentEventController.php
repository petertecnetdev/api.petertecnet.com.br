<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Events\Services\AdminEstablishmentEventService;
use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    public function show(Request $request, Establishment $establishment, Event $event): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
        ]);

        return response()->json(
            $this->events->show($establishment, $event, (int) $data['app_id'])
        );
    }

    public function update(Request $request, Establishment $establishment, Event $event): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:50000'],
            'category' => ['nullable', 'string', 'max:150'],
            'event_format' => ['required', Rule::in(['in_person', 'online', 'hybrid'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'venue' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'uf' => ['nullable', 'string', 'size:2'],
            'country' => ['nullable', 'string', 'max:120'],
            'online_platform' => ['nullable', 'string', 'max:120'],
            'online_url' => ['nullable', 'url', 'max:1000'],
            'online_instructions' => ['nullable', 'string', 'max:5000'],
            'max_attendees' => ['nullable', 'integer', 'min:0'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            'is_approved' => ['sometimes', 'boolean'],
            'is_cancelled' => ['sometimes', 'boolean'],
            'is_private' => ['sometimes', 'boolean'],
            'requires_approval' => ['sometimes', 'boolean'],
            'approval_message' => ['nullable', 'string', 'max:5000'],
            'image_data_uri' => ['nullable', 'string', 'max:10000000', 'regex:/^data:image\/(?:png|jpe?g|webp);base64,/i'],
        ], [
            'title.required' => 'Informe o nome do evento.',
            'description.required' => 'Informe a descrição do evento.',
            'event_format.required' => 'Selecione o formato do evento.',
            'start_date.required' => 'Informe a data e hora de início.',
            'end_date.required' => 'Informe a data e hora de término.',
            'end_date.after' => 'O término do evento precisa ser posterior ao início.',
            'online_url.url' => 'Informe uma URL válida para o acesso online.',
            'image_data_uri.regex' => 'A imagem gerada pela IA está em um formato inválido.',
            'image_data_uri.max' => 'A imagem gerada pela IA excedeu o limite permitido.',
        ]);

        $appId = (int) $data['app_id'];
        unset($data['app_id']);

        return response()->json(
            $this->events->update(
                $establishment,
                $event,
                $appId,
                $data,
                $request->user()?->id,
                $request->ip(),
                $request->userAgent(),
            )
        );
    }

    public function duplicate(Request $request, Establishment $establishment, Event $event): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:50000'],
            'category' => ['nullable', 'string', 'max:150'],
            'event_format' => ['nullable', Rule::in(['in_person', 'online', 'hybrid'])],
            'start_date' => ['nullable', 'date', 'after:now'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'venue' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'google_maps_url' => ['nullable', 'url', 'max:1000'],
            'city' => ['nullable', 'string', 'max:120'],
            'uf' => ['nullable', 'string', 'size:2'],
            'country' => ['nullable', 'string', 'max:120'],
            'online_platform' => ['nullable', 'string', 'max:120'],
            'online_url' => ['nullable', 'url', 'max:1000'],
            'online_instructions' => ['nullable', 'string', 'max:5000'],
            'max_attendees' => ['nullable', 'integer', 'min:0'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'is_private' => ['sometimes', 'boolean'],
            'requires_approval' => ['sometimes', 'boolean'],
            'approval_message' => ['nullable', 'string', 'max:5000'],
        ], [
            'date.required' => 'Informe a nova data do evento.',
            'date.date_format' => 'Informe uma data válida.',
            'start_date.after' => 'O início da cópia precisa ficar no futuro.',
            'end_date.after' => 'O término da cópia precisa ser posterior ao início.',
        ]);

        $overrides = $data;
        unset($overrides['app_id'], $overrides['date']);

        return response()->json(
            $this->events->duplicate(
                $establishment,
                $event,
                (int) $data['app_id'],
                $data['date'],
                $request->user()?->id,
                $request->ip(),
                $request->userAgent(),
                $overrides,
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
