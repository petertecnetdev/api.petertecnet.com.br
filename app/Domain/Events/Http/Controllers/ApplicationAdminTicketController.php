<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class ApplicationAdminTicketController extends Controller
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
            'production_id' => ['nullable', 'integer', 'min:1'],
            'availability' => ['nullable', 'string', 'in:available,sold_out,expired'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Ticket::query()
            ->where('app_id', $this->context->id())
            ->with([
                'event:id,app_id,production_id,title,slug,start_date,city,venue',
                'event.production:id,app_id,name,slug,user_id',
            ])
            ->withCount([
                'passes',
                'passes as valid_passes_count' => fn ($passes) => $passes->whereNotIn('status', ['cancelled', 'refunded', 'charged_back']),
            ]);

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $query->where(function ($builder) use ($term) {
                $builder->where('name', 'like', '%'.$term.'%')
                    ->orWhere('description', 'like', '%'.$term.'%')
                    ->orWhereHas('event', fn ($event) => $event->where('title', 'like', '%'.$term.'%'))
                    ->orWhereHas('event.production', fn ($production) => $production->where('name', 'like', '%'.$term.'%'));
            });
        }

        if (! empty($data['event_id'])) {
            $query->where('event_id', (int) $data['event_id']);
        }

        if (! empty($data['production_id'])) {
            $productionId = (int) $data['production_id'];
            $query->whereHas('event', fn ($event) => $event->where('production_id', $productionId));
        }

        if (($data['availability'] ?? null) === 'expired') {
            $query->whereNotNull('limit_date')->where('limit_date', '<', now());
        } elseif (($data['availability'] ?? null) === 'available') {
            $query->where(fn ($builder) => $builder->whereNull('limit_date')->orWhere('limit_date', '>=', now()));
        }

        $tickets = $query->latest('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $tickets,
        ]);
    }

    public function update(Request $request, int $ticket): JsonResponse
    {
        $model = Ticket::query()
            ->where('app_id', $this->context->id())
            ->findOrFail($ticket);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'limit_date' => ['sometimes', 'nullable', 'date'],
            'ticket_type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:0'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $before = Arr::only($model->toArray(), array_keys($data));
        $model->fill($data)->save();
        $fresh = $model->fresh()->load('event.production');

        $this->admin->auditAction($this->context->id(), $request->user(), null, 'admin_ticket_updated', [
            'ticket_id' => $model->id,
            'event_id' => $model->event_id,
            'before' => $before,
            'after' => Arr::only($fresh->toArray(), array_keys($data)),
        ], $this->auditContext($request));

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $fresh,
        ]);
    }

    public function destroy(Request $request, int $ticket): JsonResponse
    {
        $model = Ticket::query()
            ->where('app_id', $this->context->id())
            ->withCount('passes')
            ->findOrFail($ticket);

        if ($model->passes_count > 0) {
            throw ValidationException::withMessages([
                'ticket' => 'Este ingresso possui emissões vinculadas. Preserve o histórico financeiro e operacional em vez de apagá-lo fisicamente.',
            ]);
        }

        $snapshot = Arr::only($model->toArray(), ['id', 'event_id', 'name', 'price', 'quantity', 'ticket_type']);
        $model->delete();

        $this->admin->auditAction($this->context->id(), $request->user(), null, 'admin_ticket_deleted', $snapshot, $this->auditContext($request));

        return response()->json(['success' => true]);
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
