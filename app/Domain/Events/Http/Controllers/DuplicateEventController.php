<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventDuplicationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class DuplicateEventController extends Controller
{
    public function __construct(private readonly EventDuplicationService $duplication) {}

    public function __invoke(Request $request, int $id)
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ], [
            'date.required' => 'Informe a nova data do evento.',
            'date.date_format' => 'Informe a nova data no formato válido.',
        ]);

        return response()->json(
            $this->duplication->duplicate($request->user(), $id, $data['date']),
            201
        );
    }
}
