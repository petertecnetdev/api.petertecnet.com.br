<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventDuplicationService;
use App\Http\Controllers\Controller;
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

        $duplicate = $this->duplicator->duplicateForApplicationUser(
            $id,
            $data['date'],
            $this->context->id(),
            $this->context->slug(),
            $request->user(),
        );

        return response()->json([
            'message' => 'Evento duplicado como rascunho. Revise a nova data e publique quando estiver pronto.',
            'event' => $duplicate,
            'copied' => [
                'tickets' => $duplicate->tickets_count,
                'artists' => $duplicate->artists->count(),
                'items' => $duplicate->commerce_items_count,
            ],
        ], 201);
    }
}
