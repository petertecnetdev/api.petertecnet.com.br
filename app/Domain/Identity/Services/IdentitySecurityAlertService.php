<?php

namespace App\Domain\Identity\Services;

use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class IdentitySecurityAlertService
{
    public function __construct(
        private readonly IdentityChallengeService $challenges,
        private readonly IdentityDeviceService $devices,
        private readonly IdentityAuditService $audit,
    ) {
    }

    public function newSession(User $user, Request $request, ?Application $application, string $sessionId, array $risk = []): void
    {
        if (! config('identity.features.security_alerts', true) || ! $user->email) {
            return;
        }

        $issued = $this->challenges->issue(
            'security_not_me',
            $user,
            $application,
            ['session_id' => $sessionId, 'risk' => $risk],
            max((int) config('identity.security_alerts.token_ttl_minutes', 1440), 10),
            $request
        );

        $context = $this->devices->context($request);
        $url = rtrim((string) config('identity.account_url', 'https://petertecnet.com.br'), '/')
            .'/?identity_not_me='.rawurlencode($issued['token']);
        $app = $application?->name ?: 'Conta Peter Tecnet';
        $message = "Novo acesso detectado em {$app}.\n\n"
            ."Dispositivo: {$context['label']}\n"
            ."País/região: ".($context['country'] ?: 'não identificado')."\n"
            ."Horário: ".now()->format('d/m/Y H:i')."\n\n"
            ."Se foi você, nenhuma ação é necessária. Se não reconhece este acesso, use este link para encerrar as sessões e proteger a conta:\n{$url}";

        try {
            Mail::raw($message, fn ($mail) => $mail->to($user->email)->subject('Novo acesso à sua Conta Peter Tecnet'));
        } catch (\Throwable $e) {
            Log::warning('Falha ao enviar alerta de segurança da Identity Platform.', ['user_id' => $user->id, 'message' => $e->getMessage()]);
        }

        $this->audit->record('security_alert_sent', $user, $request, $application, ['session_id' => $sessionId, 'risk' => $risk]);
    }
}
