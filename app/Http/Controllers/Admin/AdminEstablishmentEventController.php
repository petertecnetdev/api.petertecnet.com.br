<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Events\Services\AdminEstablishmentEventService;
use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    private function authorizeAccess(Request $request): void
    {
        $email = strtolower(trim((string) $request->user()?->email));
        abort_unless($email === 'petertecnet@gmail.com', 403, 'Apenas o administrador principal pode gerenciar eventos por este painel.');
    }
}
