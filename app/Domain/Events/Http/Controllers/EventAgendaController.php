<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventAgendaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class EventAgendaController extends Controller
{
    public function __construct(
        private readonly EventAgendaService $agenda,
    ) {}

    public function index(Request $request, int $productionId)
    {
        return response()->json(
            $this->agenda->index($productionId, $request->user())
        );
    }

    public function setAgendaStatus(Request $request, int $productionId)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json(
            $this->agenda->setAgendaStatus($productionId, $request->user(), (bool) $data['is_active'])
        );
    }

    public function store(Request $request, int $productionId)
    {
        return response()->json(
            $this->agenda->store(
                $productionId,
                $request->user(),
                $request->all(),
                $request->file('image')
            ),
            201
        );
    }

    public function update(Request $request, int $scheduleId)
    {
        return response()->json(
            $this->agenda->update(
                $scheduleId,
                $request->user(),
                $request->all(),
                $request->file('image')
            )
        );
    }

    public function setItemStatus(Request $request, int $scheduleId)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json(
            $this->agenda->setItemStatus($scheduleId, $request->user(), (bool) $data['is_active'])
        );
    }

    public function destroy(Request $request, int $scheduleId)
    {
        return response()->json(
            $this->agenda->destroy($scheduleId, $request->user())
        );
    }

    public function generate(Request $request, int $scheduleId)
    {
        $result = $this->agenda->generate($scheduleId, $request->user());

        return response()->json($result['body'], $result['status']);
    }

    public function generateUpcoming(Request $request, int $productionId)
    {
        return response()->json(
            $this->agenda->generateUpcoming($productionId, $request->user())
        );
    }
}
