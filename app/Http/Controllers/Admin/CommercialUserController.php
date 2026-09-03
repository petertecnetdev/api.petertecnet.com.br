<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommercialUserController extends Controller
{
    public function updateIdentity(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && (
            $actor->hasProfile('Administrador')
            || $actor->hasPermission('onboarding_manage')
            || $actor->hasPermission('ecosystem_manage')
        ), 403, 'Usuário sem permissão para complementar a identidade do cliente.');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:150'],
        ]);

        $user->update($data);

        return response()->json([
            'user' => $user->fresh(['applications:id,name,slug,url']),
        ]);
    }
}
