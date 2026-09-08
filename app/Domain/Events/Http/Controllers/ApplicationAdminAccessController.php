<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Http\Controllers\Controller;
use App\Models\EventPass;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApplicationAdminAccessController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ApplicationAdminService $admin,
    ) {
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

        $before = [
            'status' => $model->status,
            'checked_in_at' => $model->checked_in_at,
            'checked_in_by' => $model->checked_in_by,
        ];

        $model->forceFill([
            'status' => 'cancelled',
            'checked_in_at' => null,
            'checked_in_by' => null,
        ])->save();

        $fresh = $model->fresh()->load(['ticket', 'event.production', 'user']);

        $this->admin->auditAction($this->context->id(), $request->user(), $fresh->user, 'admin_pass_invalidated', [
            'pass_id' => $fresh->id,
            'ticket_id' => $fresh->ticket_id,
            'event_id' => $fresh->event_id,
            'reason' => $data['reason'],
            'before' => $before,
            'after' => [
                'status' => $fresh->status,
                'checked_in_at' => $fresh->checked_in_at,
                'checked_in_by' => $fresh->checked_in_by,
            ],
        ], $this->auditContext($request));

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'message' => 'Ingresso invalidado administrativamente.',
            'reason' => $data['reason'],
            'data' => $fresh,
        ]);
    }

    private function auditContext(Request $request): array
    {
        return [
            'request_id' => $request->attributes->get('request_id') ?: $request->header('X-Request-ID'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'authority' => $request->attributes->get('admin_authority'),
            'scope' => $request->attributes->get('admin_scope'),
        ];
    }
}
