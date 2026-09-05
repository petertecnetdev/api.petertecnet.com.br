<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminUserDetailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUserDetailController extends Controller
{
    public function show(Request $request, User $user, AdminUserDetailService $service): JsonResponse
    {
        return response()->json($service->detail($user));
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
}
