<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventSeriesService;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class EventSeriesController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EventSeriesService $series,
    ) {}

    public function __invoke(Request $request, int $id)
    {
        $user = $request->user();
        $event = Event::query()
            ->where('app_id', $this->context->id())
            ->with('production:id,app_id,user_id')
            ->findOrFail($id);

        abort_unless(
            $event->production && ($user->hasProfile('Administrador') || (int) $event->production->user_id === (int) $user->id),
            403,
            'Você não pode criar novas ocorrências deste evento.'
        );

        return response()->json(
            $this->series->create($event, $this->context->id(), $this->context->slug(), $request->all()),
            201,
        );
    }
}
