<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentityChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class IdentityStepUpService
{
    public function __construct(
        private readonly IdentityChallengeService $challenges,
        private readonly IdentitySessionService $sessions,
    ) {
    }

    public function issue(User $user, Request $request, string $action, string $method): array
    {
        $this->assertAction($action);
        $session = $this->sessions->current();
        $issued = $this->challenges->issue(
            'step_up_grant',
            $user,
            $session?->application,
            [
                'action' => $action,
                'method' => $method,
                'session_id' => $session?->session_id,
            ],
            max((int) config('identity.step_up.ttl_minutes', 10), 1),
            $request,
            40,
        );

        return [
            'token' => $issued['token'],
            'action' => $action,
            'method' => $method,
            'expires_in' => max((int) config('identity.step_up.ttl_minutes', 10), 1) * 60,
        ];
    }

    public function consume(Request $request, string $action): ?IdentityChallenge
    {
        $this->assertAction($action);
        $raw = trim((string) $request->header('X-Peter-Step-Up', ''));
        if ($raw === '') return null;

        $challenge = $this->challenges->consume('step_up_grant', $raw, false);
        if (! $challenge || (int) $challenge->user_id !== (int) $request->user('api')?->id) return null;

        $payload = is_array($challenge->payload) ? $challenge->payload : [];
        if (! hash_equals($action, (string) ($payload['action'] ?? ''))) return null;

        $current = $this->sessions->current();
        $expectedSessionId = (string) ($payload['session_id'] ?? '');
        if ($expectedSessionId !== '' && (! $current || ! hash_equals($expectedSessionId, (string) $current->session_id))) {
            return null;
        }

        return $this->challenges->consumeModel($challenge) ? $challenge->fresh() : null;
    }

    public function allowedActions(): array
    {
        return array_values(array_unique(array_filter(config('identity.step_up.actions', []), 'is_string')));
    }

    private function assertAction(string $action): void
    {
        if (! in_array($action, $this->allowedActions(), true)) {
            throw ValidationException::withMessages(['action' => 'Ação de segurança não reconhecida.']);
        }
    }
}
