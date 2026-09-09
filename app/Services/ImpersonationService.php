<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ImpersonationService
{
    public function start(User $actor, User $target, Application $application, string $reason, Request $request): array
    {
        if ($actor->is($target)) {
            throw new HttpException(422, 'Não é possível iniciar impersonação do próprio usuário.');
        }

        if (! $application->is_active || ! $application->url) {
            throw new HttpException(422, 'A aplicação selecionada não está ativa ou não possui URL configurada.');
        }

        $this->assertTrustedApplicationUrl($application->url);

        $plainHandoff = Str::random(64);
        $ttlMinutes = max((int) config('impersonation.ttl_minutes', 30), 5);
        $handoffTtl = max((int) config('impersonation.handoff_ttl_seconds', 60), 15);

        $session = DB::transaction(function () use ($actor, $target, $application, $reason, $request, $plainHandoff, $ttlMinutes, $handoffTtl) {
            ImpersonationSession::query()
                ->where('impersonator_user_id', $actor->id)
                ->whereNull('ended_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->get()
                ->each(fn (ImpersonationSession $active) => $active->finish($actor, 'superseded'));

            return ImpersonationSession::create([
                'uuid' => (string) Str::uuid(),
                'impersonator_user_id' => $actor->id,
                'impersonated_user_id' => $target->id,
                'application_id' => $application->id,
                'reason' => trim($reason),
                'handoff_token_hash' => hash('sha256', $plainHandoff),
                'handoff_expires_at' => now()->addSeconds($handoffTtl),
                'started_at' => now(),
                'expires_at' => now()->addMinutes($ttlMinutes),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'source' => 'admincenter',
                    'application_slug' => $application->slug,
                ],
            ]);
        });

        return [
            'session' => $session->load(['impersonator', 'impersonatedUser', 'application']),
            'handoff_url' => $this->handoffUrl($application->url, $plainHandoff),
            'handoff_expires_at' => $session->handoff_expires_at,
        ];
    }

    public function exchange(string $plainHandoff, ?string $applicationSlug = null): array
    {
        return DB::transaction(function () use ($plainHandoff, $applicationSlug) {
            $session = ImpersonationSession::query()
                ->with(['impersonator', 'impersonatedUser', 'application'])
                ->where('handoff_token_hash', hash('sha256', $plainHandoff))
                ->lockForUpdate()
                ->first();

            if (! $session || ! $session->isActive()) {
                throw new HttpException(401, 'Sessão de impersonação inválida ou expirada.');
            }

            if ($session->handoff_used_at || ! $session->handoff_expires_at || $session->handoff_expires_at->isPast()) {
                throw new HttpException(401, 'Este acesso temporário já foi utilizado ou expirou.');
            }

            if ($applicationSlug && strcasecmp((string) $session->application?->slug, trim($applicationSlug)) !== 0) {
                throw new HttpException(403, 'O acesso temporário não pertence a esta aplicação.');
            }

            if (! $session->impersonatedUser || ! $session->impersonator || ! $session->application) {
                $session->finish(null, 'invalid_reference');
                throw new HttpException(401, 'A sessão de impersonação perdeu uma referência necessária.');
            }

            $session->forceFill([
                'handoff_used_at' => now(),
                'handoff_token_hash' => null,
                'handoff_expires_at' => null,
            ])->save();

            $token = auth('api')->claims([
                'impersonation_session_id' => $session->id,
                'impersonation_uuid' => $session->uuid,
                'actor_user_id' => $session->impersonator_user_id,
                'application_id' => $session->application_id,
                'impersonated' => true,
            ])->login($session->impersonatedUser);

            return [
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => max(now()->diffInSeconds($session->expires_at, false), 0),
                'user' => $session->impersonatedUser,
                'impersonation' => $this->payload($session),
            ];
        });
    }

    public function currentFromToken(): ?ImpersonationSession
    {
        try {
            $user = auth('api')->user();
            if (! $user) return null;

            $sessionId = auth('api')->payload()->get('impersonation_session_id');
            if (! $sessionId || ! is_numeric($sessionId)) return null;

            $session = ImpersonationSession::query()
                ->with(['impersonator', 'impersonatedUser', 'application'])
                ->find((int) $sessionId);

            if (! $session || ! $session->isActive() || (int) $session->impersonated_user_id !== (int) $user->id) {
                return null;
            }

            return $session;
        } catch (\Throwable) {
            return null;
        }
    }

    public function payload(ImpersonationSession $session): array
    {
        $session->loadMissing(['impersonator', 'impersonatedUser', 'application']);

        return [
            'id' => $session->id,
            'uuid' => $session->uuid,
            'reason' => $session->reason,
            'started_at' => $session->started_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'active' => $session->isActive(),
            'actor' => $session->impersonator ? [
                'id' => $session->impersonator->id,
                'name' => trim(($session->impersonator->first_name ?? '').' '.($session->impersonator->last_name ?? '')) ?: $session->impersonator->user_name,
                'email' => $session->impersonator->email,
            ] : null,
            'effective_user' => $session->impersonatedUser ? [
                'id' => $session->impersonatedUser->id,
                'name' => trim(($session->impersonatedUser->first_name ?? '').' '.($session->impersonatedUser->last_name ?? '')) ?: $session->impersonatedUser->user_name,
                'email' => $session->impersonatedUser->email,
            ] : null,
            'application' => $session->application ? [
                'id' => $session->application->id,
                'name' => $session->application->name,
                'slug' => $session->application->slug,
                'url' => $session->application->url,
            ] : null,
        ];
    }

    private function handoffUrl(string $applicationUrl, string $plainHandoff): string
    {
        $separator = str_contains($applicationUrl, '?') ? '&' : '?';
        return rtrim($applicationUrl, '/') . '/' . $separator . http_build_query(['pt_impersonation' => $plainHandoff]);
    }

    private function assertTrustedApplicationUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $root = strtolower(trim((string) config('impersonation.trusted_root_domain', 'petertecnet.com.br')));

        if ($scheme !== 'https' || ($host !== $root && ! str_ends_with($host, '.'.$root))) {
            if (! app()->environment(['local', 'testing'])) {
                throw new HttpException(422, 'A URL da aplicação não pertence ao domínio confiável do ecossistema.');
            }
        }
    }
}
