<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EventPass;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApplicationAdminAccessController extends Controller
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'event_id' => ['nullable', 'integer', 'min:1'],
            'checked_in' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = EventPass::query()
            ->whereHas('ticket', fn ($ticket) => $ticket->where('app_id', $this->context->id()))
            ->with([
                'ticket:id,app_id,event_id,name,price',
                'event:id,app_id,production_id,title,slug,start_date,end_date',
                'event.production:id,app_id,name,slug',
                'user:id,first_name,last_name,email',
                'checkedInBy:id,first_name,last_name,email',
            ]);

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $query->where(function ($builder) use ($term) {
                $builder->where('holder_name', 'like', '%'.$term.'%')
                    ->orWhere('holder_email', 'like', '%'.$term.'%')
                    ->orWhere('token', 'like', '%'.$term.'%')
                    ->orWhereHas('event', fn ($event) => $event->where('title', 'like', '%'.$term.'%'))
                    ->orWhereHas('event.production', fn ($production) => $production->where('name', 'like', '%'.$term.'%'));
            });
        }

        if (! empty($data['event_id'])) {
            $query->where('event_id', (int) $data['event_id']);
        }

        if (array_key_exists('checked_in', $data)) {
            $data['checked_in'] ? $query->whereNotNull('checked_in_at') : $query->whereNull('checked_in_at');
        }

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $query->latest('id')->paginate((int) ($data['per_page'] ?? 25)),
        ]);
    }

    public function invalidate(Request $request, int $pass): JsonResponse
    {
        $model = EventPass::query()
            ->whereHas('ticket', fn ($ticket) => $ticket->where('app_id', $this->context->id()))
            ->findOrFail($pass);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $model->forceFill([
            'status' => 'cancelled',
            'checked_in_at' => null,
            'checked_in_by' => null,
        ])->save();

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'message' => 'Ingresso invalidado administrativamente.',
            'reason' => $data['reason'],
            'data' => $model->fresh()->load(['ticket', 'event.production', 'user']),
        ]);
    }
}
