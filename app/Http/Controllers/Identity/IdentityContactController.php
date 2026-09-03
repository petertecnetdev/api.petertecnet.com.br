<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Services\IdentityApplicationResolver;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityChallengeService;
use App\Domain\Identity\Services\IdentityGlobalSessionService;
use App\Domain\Identity\Services\IdentityIdentifierService;
use App\Domain\Identity\Services\IdentityPhoneDeliveryService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class IdentityContactController extends Controller
{
    public function __construct(
        private readonly IdentityChallengeService $challenges,
        private readonly IdentityIdentifierService $identifiers,
        private readonly IdentityPhoneDeliveryService $phones,
        private readonly IdentityAuditService $audit,
        private readonly IdentityApplicationResolver $applications,
        private readonly IdentitySessionService $sessions,
        private readonly IdentityGlobalSessionService $globalSessions,
    ) {
    }

    public function requestEmailChange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'application' => ['nullable', 'string', 'max:120'],
        ]);
        $user = $request->user('api');
        $email = Str::lower(trim($data['email']));
        abort_if(Str::lower((string) $user->email) === $email, 422, 'Informe um e-mail diferente do atual.');
        abort_if($this->identifiers->resolve($email)?->id && (int) $this->identifiers->resolve($email)->id !== (int) $user->id, 409, 'Este e-mail já pertence a outra Conta Peter Tecnet.');

        $application = $this->applications->resolve($request, $data['application'] ?? null);
        $issued = $this->challenges->issue(
            'contact_email_change',
            $user,
            $application,
            ['email' => $email],
            max((int) config('identity.contacts.verification_ttl_minutes', 10), 5),
            $request
        );
        $url = rtrim((string) config('identity.account_url', 'https://petertecnet.com.br'), '/')
            .'/?identity_email_confirm='.rawurlencode($issued['token']);

        Mail::raw(
            "Confirme seu novo e-mail da Conta Peter Tecnet usando o link abaixo.\n\n{$url}\n\nSe você não solicitou a alteração, ignore esta mensagem.",
            fn ($mail) => $mail->to($email)->subject('Confirme seu novo e-mail · Peter Tecnet')
        );
        $this->audit->record('email_change_requested', $user, $request, $application, ['new_email_hint' => $this->maskEmail($email)], true);

        return response()->json(['success' => true, 'message' => 'Enviamos a confirmação para o novo e-mail.']);
    }

    public function confirmEmailChange(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);
        $challenge = $this->challenges->consume('contact_email_change', $data['token']);
        if (! $challenge || ! $challenge->user) {
            return response()->json(['success' => false, 'code' => 'EMAIL_CHANGE_INVALID', 'message' => 'Confirmação inválida ou expirada.'], 410);
        }

        $email = Str::lower(trim((string) data_get($challenge->payload, 'email', '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['success' => false, 'code' => 'EMAIL_CHANGE_INVALID'], 422);
        }

        $user = $challenge->user;
        $owner = $this->identifiers->resolve($email);
        if ($owner && (int) $owner->id !== (int) $user->id) {
            return response()->json(['success' => false, 'code' => 'IDENTIFIER_CONFLICT', 'message' => 'Este e-mail já está vinculado a outra conta.'], 409);
        }

        $user->forceFill([
            'email' => $email,
            'email_verified_at' => now(),
            'auth_version' => max((int) ($user->auth_version ?? 1), 1) + 1,
        ])->save();
        $this->identifiers->add($user, 'email', $email, true, true);
        $this->sessions->revokeAll($user, 'email_changed');
        $this->globalSessions->revokeAll($user, 'email_changed');
        $this->audit->record('email_changed', $user, $request, $challenge->application, ['email_hint' => $this->maskEmail($email)], true);

        return response()->json(['success' => true, 'message' => 'E-mail atualizado. Entre novamente para continuar.']);
    }

    public function requestPhoneVerification(Request $request): JsonResponse
    {
        abort_unless(config('identity.features.phone', false), 404);
        $data = $request->validate(['phone' => ['required', 'string', 'max:40']]);
        $user = $request->user('api');
        [, $phone] = $this->identifiers->classify($data['phone']);
        $phone = $this->identifiers->normalize('phone', $phone);
        abort_unless(strlen($phone) >= 12 && strlen($phone) <= 15, 422, 'Telefone inválido.');

        $owner = $this->identifiers->resolve('+'.$phone);
        abort_if($owner && (int) $owner->id !== (int) $user->id, 409, 'Este telefone já pertence a outra Conta Peter Tecnet.');
        if (! $this->phones->available()) {
            return response()->json(['success' => false, 'code' => 'PHONE_DELIVERY_NOT_CONFIGURED', 'message' => 'A verificação de telefone está temporariamente indisponível.'], 503);
        }

        $code = (string) random_int(100000, 999999);
        $issued = $this->challenges->issue(
            'contact_phone_verify',
            $user,
            null,
            ['phone' => $phone, 'code_hash' => hash('sha256', $code)],
            max((int) config('identity.contacts.verification_ttl_minutes', 10), 5),
            $request
        );
        $this->phones->send($phone, $code);
        $this->audit->record('phone_verification_requested', $user, $request, null, ['phone_hint' => '•••• '.substr($phone, -4)], true);

        return response()->json([
            'success' => true,
            'data' => ['challenge' => $issued['token'], 'expires_in' => max((int) config('identity.contacts.verification_ttl_minutes', 10), 5) * 60],
        ]);
    }

    public function confirmPhoneVerification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:255'],
            'code' => ['required', 'digits:6'],
        ]);
        $user = $request->user('api');
        $challenge = $this->challenges->consume('contact_phone_verify', $data['challenge'], false);
        if (! $challenge || (int) $challenge->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'code' => 'PHONE_CHALLENGE_INVALID', 'message' => 'Verificação inválida ou expirada.'], 410);
        }

        if (! hash_equals((string) data_get($challenge->payload, 'code_hash', ''), hash('sha256', $data['code']))) {
            return response()->json(['success' => false, 'code' => 'PHONE_CODE_INVALID', 'message' => 'Código inválido.'], 422);
        }
        abort_unless($this->challenges->consumeModel($challenge), 410, 'Verificação já utilizada.');

        $phone = (string) data_get($challenge->payload, 'phone', '');
        $this->identifiers->add($user, 'phone', $phone, true, true);
        $user->forceFill(['phone' => $phone])->save();
        $this->audit->record('phone_verified', $user, $request, null, ['phone_hint' => '•••• '.substr($phone, -4)], true);

        return response()->json(['success' => true, 'message' => 'Telefone verificado.']);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, min(2, mb_strlen($local))).'***@'.$domain;
    }
}
