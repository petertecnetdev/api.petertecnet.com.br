<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUserAnnotation;
use App\Models\User;
use App\Services\Admin\AdminUserAccountAccessService;
use App\Services\Admin\AdminUserDetailService;
use App\Services\Admin\AdminUserIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUserDetailController extends Controller
{
    public function show(Request $request, User $user, AdminUserDetailService $service, AdminUserIntelligenceService $intelligence): JsonResponse
    {
        return response()->json($intelligence->augment($user, $service->detail($user)));
    }

    public function activity(Request $request, User $user, AdminUserDetailService $service): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'type' => ['nullable', 'string', 'max:100'],
            'outcome' => ['nullable', 'string', 'max:80'],
            'severity' => ['nullable', 'string', 'max:80'],
            'environment' => ['nullable', 'string', 'max:80'],
            'entity_type' => ['nullable', 'string', 'max:150'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:180'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:20', 'max:100'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
        ]);

        return response()->json($service->activity($user, $data));
    }

    public function storeNote(Request $request, User $user, AdminUserIntelligenceService $service): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'message' => 'Nota administrativa registrada.',
            'note' => $service->storeNote($user, $request->user(), $data, $request),
        ], 201);
    }

    public function deleteNote(Request $request, User $user, AdminUserAnnotation $annotation, AdminUserIntelligenceService $service): JsonResponse
    {
        abort_unless((int) $annotation->target_user_id === (int) $user->id && $annotation->kind === 'note', 404);
        $service->deleteNote($user, $annotation, $request->user(), $request);

        return response()->json(['message' => 'Nota administrativa removida.']);
    }

    public function updateTags(Request $request, User $user, AdminUserIntelligenceService $service): JsonResponse
    {
        $data = $request->validate([
            'tags' => ['present', 'array', 'max:30'],
            'tags.*' => ['string', 'max:80', 'distinct'],
        ]);

        return response()->json([
            'message' => 'Segmentação atualizada.',
            'tags' => $service->replaceTags($user, $request->user(), $data['tags'], $request),
        ]);
    }

    public function revokeSessions(Request $request, User $user, AdminUserIntelligenceService $service): JsonResponse
    {
        return response()->json($service->revokeSessions($user, $request->user(), $request));
    }

    public function accountAccess(Request $request, User $user, AdminUserAccountAccessService $service): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'blocked'])],
        ]);

        return response()->json($service->set($user, $data['status'], $request));
    }
}
