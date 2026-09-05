<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Interaction;
use App\Services\Admin\ActivityCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityController extends Controller
{
    private const OWNER_EMAIL = 'petertecnet@gmail.com';

    public function __construct(private readonly ActivityCenterService $activities)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);
        return response()->json($this->activities->index($this->filters($request)));
    }

    public function overview(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);
        return response()->json($this->activities->overview($this->filters($request, false)));
    }

    public function facets(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);
        return response()->json($this->activities->facets());
    }

    public function show(Request $request, Interaction $interaction): JsonResponse
    {
        $this->authorizeOwner($request);
        return response()->json($this->activities->show($interaction));
    }

    private function filters(Request $request, bool $withPagination = true): array
    {
        $rules = [
            'range' => ['nullable', Rule::in(['1h', '24h', '7d', '30d', '90d'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:150'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'establishment_id' => ['nullable', 'integer', 'exists:establishments,id'],
            'type' => ['nullable', 'string', 'max:100'],
            'outcome' => ['nullable', Rule::in(['success', 'pending', 'cancelled', 'denied', 'error'])],
            'severity' => ['nullable', Rule::in(['normal', 'attention', 'suspicious', 'critical'])],
            'environment' => ['nullable', 'string', 'max:50'],
            'method' => ['nullable', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'entity_type' => ['nullable', 'string', 'max:150'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', Rule::in(['frontend', 'domain', 'backend', 'system'])],
            'session_key' => ['nullable', 'string', 'max:100'],
            'correlation_id' => ['nullable', 'string', 'max:150'],
            'request_id' => ['nullable', 'string', 'max:150'],
        ];

        if ($withPagination) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['per_page'] = ['nullable', 'integer', 'min:20', 'max:100'];
        }

        return $request->validate($rules);
    }

    private function authorizeOwner(Request $request): void
    {
        $email = mb_strtolower(trim((string) $request->user()?->email));
        abort_unless($email === self::OWNER_EMAIL, 403, 'Acesso ao Admin Center restrito ao administrador da Peter Tecnet.');
    }
}
