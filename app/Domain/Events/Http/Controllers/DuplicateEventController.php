<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventDuplicationService;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

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
        ], [
            'date.required' => 'Informe a nova data do evento.',
            'date.date_format' => 'Informe a nova data no formato válido.',
        ]);

        $source = Event::query()
            ->where('app_id', $this->context->id())
            ->with('production:id,app_id,name,slug,user_id,app_slug')
            ->findOrFail($id);

        abort_unless(
            $source->production && (int) $source->production->app_id === $this->context->id(),
            404,
            'Evento não encontrado neste contexto.'
        );
        abort_unless(
            $request->user()->hasProfile('Administrador')
                || (int) $source->production->user_id === (int) $request->user()->id,
            403,
            'Você não pode duplicar este evento.'
        );

        $duplicate = $this->duplicator->duplicate(
            $source,
            $data['date'],
            $this->context->id(),
            $this->context->slug(),
        );

        return response()->json([
            'message' => 'Evento duplicado como rascunho. Revise a nova data e publique quando estiver pronto.',
            'event' => $duplicate,
            'copied' => [
                'tickets' => $duplicate->tickets_count,
                'artists' => $duplicate->artists->count(),
            ],
        ], 201);
    }
}
