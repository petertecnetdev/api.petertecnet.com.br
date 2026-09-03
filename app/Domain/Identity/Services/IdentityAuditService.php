<?php

namespace App\Domain\Identity\Services;

use App\Models\Application;
use App\Models\EcosystemAuditLog;
use App\Models\User;
use App\Services\AppNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class IdentityAuditService
{
    public function __construct(private readonly AppNotificationService $notifications)
    {
    }

    public function record(
        string $event,
        ?User $user,
        Request $request,
        ?Application $application = null,
        array $metadata = [],
        bool $alert = false,
    ): void {
        EcosystemAuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => 'identity.' . $event,
            'entity_type' => $application ? Application::class : User::class,
            'entity_id' => $application?->id ?? $user?->id,
            'before' => null,
            'after' => array_merge([
                'application_id' => $application?->id,
                'application_slug' => $application?->slug,
            ], $metadata),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        if (! $alert || ! $user) {
            return;
        }

        $copy = $this->alertCopy($event, $metadata);
        if (! $copy) {
            return;
        }

        if ($application) {
            try {
                $this->notifications->sendToUser((int) $application->id, (int) $user->id, [
                    'type' => 'security',
                    'title' => $copy['title'],
                    'message' => $copy['message'],
                    'reference_type' => 'identity',
                    'data' => array_merge($metadata, [
                        'event' => $event,
                        'ip' => $request->ip(),
                    ]),
                ]);
            } catch (\Throwable $e) {
                Log::notice('Falha ao criar alerta de segurança no aplicativo.', [
                    'event' => $event,
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($user->email) {
            try {
                $details = trim($copy['message'] . "\n\nIP: " . ($request->ip() ?: 'não identificado'));
                Mail::raw($details, function ($message) use ($user, $copy) {
                    $message->to($user->email)->subject($copy['title'] . ' · Peter Tecnet');
                });
            } catch (\Throwable $e) {
                Log::notice('Falha ao enviar alerta de segurança por e-mail.', [
                    'event' => $event,
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function alertCopy(string $event, array $metadata): ?array
    {
        return match ($event) {
            'new_session' => [
                'title' => 'Novo acesso à sua conta',
                'message' => 'Um novo acesso foi iniciado' . (! empty($metadata['device']) ? ' em ' . $metadata['device'] : '') . '. Se não foi você, encerre a sessão nas configurações de segurança.',
            ],
            'password_changed' => [
                'title' => 'Senha alterada',
                'message' => 'A senha da sua Conta Peter Tecnet foi alterada. Se não foi você, recupere a conta imediatamente.',
            ],
            'two_factor_enabled' => [
                'title' => 'Verificação em duas etapas ativada',
                'message' => 'A proteção em duas etapas foi ativada na sua Conta Peter Tecnet.',
            ],
            'two_factor_disabled' => [
                'title' => 'Verificação em duas etapas desativada',
                'message' => 'A proteção em duas etapas foi desativada. Se não foi você, revise suas sessões agora.',
            ],
            'passkey_registered' => [
                'title' => 'Nova passkey cadastrada',
                'message' => 'Uma nova passkey foi adicionada à sua Conta Peter Tecnet.',
            ],
            'all_sessions_revoked' => [
                'title' => 'Sessões encerradas',
                'message' => 'As outras sessões da sua Conta Peter Tecnet foram encerradas.',
            ],
            default => null,
        };
    }
}
