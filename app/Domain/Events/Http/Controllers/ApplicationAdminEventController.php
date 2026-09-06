<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventSeriesService;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class ApplicationAdminEventController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EventSeriesService $series,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'production_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Event::query()
            ->where('app_id', $this->context->id())
            ->with('production:id,app_id,name,slug,user_id,app_slug')
            ->withCount('tickets');

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $query->where(fn ($builder) => $builder
                ->where('title', 'like', '%'.$term.'%')
                ->orWhere('city', 'like', '%'.$term.'%')
                ->orWhere('venue', 'like', '%'.$term.'%'));
        }
        if (! empty($data['production_id'])) {
            $query->where('production_id', (int) $data['production_id']);
        }

        return response()->json([
            'events' => $query->orderByDesc('start_date')->paginate($data['per_page'] ?? 100),
        ]);
    }

    public function series(Request $request, int $event)
    {
        $source = Event::query()->where('app_id', $this->context->id())->findOrFail($event);

        return response()->json(
            $this->series->create($source, $this->context->id(), $this->context->slug(), $request->all()),
            201,
        );
    }
}
