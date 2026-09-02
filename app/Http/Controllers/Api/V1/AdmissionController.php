<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Events\Services\AdmissionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AdmissionCredentialResource;
use App\Http\Resources\Api\V1\AdmissionTypeResource;
use App\Models\AdmissionCredential;
use App\Models\AdmissionType;
use App\Models\Event;
use App\Support\ApiResponse;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class AdmissionController extends Controller
{
    public function __construct(
        private readonly AdmissionService $admissions,
        private readonly ApplicationContext $applicationContext,
    ) {}

    public function index(Request $request, int $event)
    {
        Event::query()->whereKey($event)->where('app_id', $this->applicationContext->id())->firstOrFail();
        $items = AdmissionType::query()->where('event_id', $event)->where('status', 'active')->orderBy('price')->get();
        return ApiResponse::success(AdmissionTypeResource::collection($items)->resolve($request));
    }

    public function store(Request $request, int $event)
    {
        Event::query()->whereKey($event)->where('app_id', $this->applicationContext->id())->firstOrFail();
        $data = $request->validate([
            'item_id' => ['nullable','integer','exists:items,id'],
            'name' => ['required','string','min:2','max:180'],
            'price' => ['required','numeric','min:0','max:99999999.99'],
            'capacity' => ['nullable','integer','min:0','max:10000000'],
            'sales_start_at' => ['nullable','date'],
            'sales_end_at' => ['nullable','date','after_or_equal:sales_start_at'],
            'metadata' => ['nullable','array'],
        ]);
        $type = $this->admissions->createType(array_merge($data, ['event_id' => $event]));
        return ApiResponse::success((new AdmissionTypeResource($type))->resolve($request), [], 201);
    }

    public function issue(Request $request, int $admission)
    {
        $type = AdmissionType::query()->findOrFail($admission);
        $data = $request->validate([
            'user_id' => ['nullable','integer','exists:users,id'],
            'source_type' => ['nullable','string','max:80'],
            'source_id' => ['nullable','integer'],
            'valid_from' => ['nullable','date'],
            'valid_until' => ['nullable','date','after_or_equal:valid_from'],
            'metadata' => ['nullable','array'],
        ]);
        $credential = $this->admissions->issue(
            $type,
            $data['user_id'] ?? $request->user()?->id,
            [
                'type' => $data['source_type'] ?? null,
                'id' => $data['source_id'] ?? null,
                'valid_from' => $data['valid_from'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
            ],
            $data['metadata'] ?? []
        );
        return ApiResponse::success((new AdmissionCredentialResource($credential))->resolve($request), [], 201);
    }

    public function mine(Request $request)
    {
        $paginator = AdmissionCredential::query()
            ->where('user_id', $request->user()->id)
            ->with('admissionType.event')
            ->latest('id')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return ApiResponse::paginated($paginator, AdmissionCredentialResource::collection($paginator->getCollection())->resolve($request));
    }
}
