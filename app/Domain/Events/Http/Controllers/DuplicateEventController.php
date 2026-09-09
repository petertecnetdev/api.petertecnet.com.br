<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventDuplicationService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DuplicateEventController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EventDuplicationService $duplicator,
    ) {}

    public function __invoke(Request $request, int $id)
    {
        $data = $request->validate([
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
            'date.date_format' => 'Informe a nova data no formato válido.',
            'start_date.after' => 'O início da cópia precisa ficar no futuro.',
            'end_date.after' => 'O término da cópia precisa ser posterior ao início.',
        ]);

        $user = $request->user();
        $overrides = $data;
        unset($overrides['date']);

        $duplicate = $this->duplicator->duplicateForApplicationUser(
            $id,
            $data['date'],
            $this->context->id(),
            $this->context->slug(),
            (int) $user->id,
            $user->hasProfile('Administrador'),
            $overrides,
        );

        return response()->json([
            'message' => 'Evento duplicado como rascunho com os dados revisados.',
            'event' => $duplicate,
            'copied' => [
                'tickets' => $duplicate->tickets_count,
                'artists' => $duplicate->artists->count(),
            ],
        ], 201);
    }
}
