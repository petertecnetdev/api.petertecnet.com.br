<?php

namespace App\Http\Controllers;

use App\Services\FlyerDateAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FlyerDateAuditController extends Controller
{
    public function __construct(private readonly FlyerDateAuditService $audits) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', Rule::in(['event', 'production'])],
            'entity_id' => ['required', 'integer', 'min:1'],
            'timezone' => ['required', 'timezone'],
            'locale' => ['nullable', 'string', 'max:16'],
            'recurring' => ['nullable', 'boolean'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
        ]);
        $result = $this->audits->queue($data, $request->user());
        return response()->json(['audit' => $result['audit']], $result['created'] ? 202 : 200);
    }

    public function show(Request $request, int $audit): JsonResponse
    {
        return response()->json(['audit' => $this->audits->findForManager($audit, $request->user())]);
    }

    public function review(Request $request, int $audit): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['corrected', 'ignored', 'review_requested'])]]);
        return response()->json(['audit' => $this->audits->review($audit, $data['action'], $request->user())]);
    }
}
