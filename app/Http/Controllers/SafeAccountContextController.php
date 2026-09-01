<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class SafeAccountContextController extends Controller
{
    public function show(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'nullable|integer|exists:applications,id',
        ]);

        $appId = isset($data['app_id']) ? (int) $data['app_id'] : null;

        $user = User::query()
            ->with(['profile', 'employer'])
            ->findOrFail(Auth::id());

        $establishments = $user->establishments()
            ->when($appId, fn ($query) => $query->where('app_id', $appId))
            ->get();

        $applications = Schema::hasTable('application_user')
            ? $user->applications()->get()
            : collect();

        $employer = $user->employer;
        $user->unsetRelation('employer');

        return response()->json([
            'message' => 'Sessão carregada com sucesso.',
            'user' => $user,
            'is_employer' => (bool) $employer,
            'employer' => $employer,
            'establishments' => $establishments,
            'applications' => $applications,
            'app_id' => $appId,
        ]);
    }
}
