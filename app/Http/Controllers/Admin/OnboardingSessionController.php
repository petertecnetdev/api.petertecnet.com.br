<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OnboardingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OnboardingSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $data = $request->validate([
            'context' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['in_progress', 'completed', 'abandoned'])],
        ]);

        $query = OnboardingSession::query()
            ->with(['subjectUser:id,first_name,last_name,email', 'application:id,name,slug', 'establishment:id,name,fantasy'])
            ->where('actor_id', $actor->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        if (! empty($data['context'])) $query->where('context', $data['context']);
        $query->where('status', $data['status'] ?? 'in_progress');

        return response()->json([
            'sessions' => $query->latest('last_activity_at')->limit(20)->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $data = $this->validateSession($request);
        $data['actor_id'] = $actor->id;
        $data['status'] = 'in_progress';
        $data['last_activity_at'] = now();
        $data['expires_at'] = now()->addDays(14);

        $session = OnboardingSession::create($data);

        return response()->json(['session' => $session->fresh()], 201);
    }

    public function update(Request $request, OnboardingSession $session): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $this->authorizeSession($actor, $session);
        $data = $this->validateSession($request, true);
        $data['last_activity_at'] = now();
        $data['expires_at'] = now()->addDays(14);
        $session->update($data);

        return response()->json(['session' => $session->fresh()]);
    }

    public function complete(Request $request, OnboardingSession $session): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $this->authorizeSession($actor, $session);
        $session->update([
            'status' => 'completed',
            'current_step' => max(4, (int) $session->current_step),
            'last_activity_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json(['session' => $session->fresh()]);
    }

    public function abandon(Request $request, OnboardingSession $session): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $this->authorizeSession($actor, $session);
        $session->update(['status' => 'abandoned', 'last_activity_at' => now()]);

        return response()->json(['session' => $session->fresh()]);
    }

    private function validateSession(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'subject_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'establishment_id' => ['nullable', 'integer', 'exists:establishments,id'],
            'context' => [$updating ? 'sometimes' : 'required', 'string', 'max:100'],
            'current_step' => ['nullable', 'integer', 'min:0', 'max:100'],
            'state' => ['nullable', 'array'],
        ]);
    }

    private function authorizeActor(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor && (
            $actor->hasProfile('Administrador')
            || $actor->hasPermission('onboarding_manage')
            || $actor->hasPermission('ecosystem_manage')
        ), 403, 'Usuário sem permissão para gerenciar sessões de onboarding.');

        return $actor;
    }

    private function authorizeSession($actor, OnboardingSession $session): void
    {
        abort_unless(
            (int) $session->actor_id === (int) $actor->id || $actor->hasProfile('Administrador'),
            403,
            'Esta sessão de onboarding pertence a outro colaborador.'
        );
    }
}
