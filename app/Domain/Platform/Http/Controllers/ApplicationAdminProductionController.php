<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Http\Controllers\Controller;
use App\Models\Production;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class ApplicationAdminProductionController extends Controller
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
            'status' => ['nullable', 'string', 'in:published,draft,cancelled'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Production::query()
            ->where('app_id', $this->context->id())
            ->with('user:id,first_name,last_name,email')
            ->withCount(['events', 'employers']);

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $query->where(fn ($builder) => $builder
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('fantasy', 'like', '%'.$term.'%')
                ->orWhere('city', 'like', '%'.$term.'%')
                ->orWhere('email', 'like', '%'.$term.'%')
                ->orWhereHas('user', fn ($user) => $user->where('email', 'like', '%'.$term.'%')));
        }

        match ($data['status'] ?? null) {
            'published' => $query->where('is_published', true)->where('is_cancelled', false),
            'draft' => $query->where('is_published', false)->where('is_cancelled', false),
            'cancelled' => $query->where('is_cancelled', true),
            default => null,
        };

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $query->latest('id')->paginate((int) ($data['per_page'] ?? 25)),
        ]);
    }

    public function update(Request $request, int $production): JsonResponse
    {
        $model = Production::query()->where('app_id', $this->context->id())->findOrFail($production);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_published' => ['sometimes', 'boolean'],
            'is_approved' => ['sometimes', 'boolean'],
            'is_cancelled' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
        ]);

        $before = Arr::only($model->toArray(), array_keys($data));
        $model->fill($data)->save();
        $fresh = $model->fresh()->load('user:id,first_name,last_name,email')->loadCount(['events', 'employers']);

        $this->admin->auditAction($this->context->id(), $request->user(), $model->user, 'admin_production_updated', [
            'production_id' => $model->id,
            'before' => $before,
            'after' => Arr::only($fresh->toArray(), array_keys($data)),
        ], $this->auditContext($request));

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
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
