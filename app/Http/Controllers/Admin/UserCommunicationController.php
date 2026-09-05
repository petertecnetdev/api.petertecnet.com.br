<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\ResendVerificationCodeMail;
use App\Models\EcosystemAuditLog;
use App\Models\User;
use App\Services\UserOnboardingCommunicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserCommunicationController extends Controller
{
    public function resend(Request $request, UserOnboardingCommunicationService $communications): JsonResponse
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:user_id'],
        ]);

        $user = ! empty($data['user_id'])
            ? User::query()->findOrFail((int) $data['user_id'])
            : User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($data['email']))])->firstOrFail();

        $application = $user->applications()
            ->wherePivot('status', 'pending')
            ->orderByPivot('updated_at', 'desc')
            ->first();

        $application ??= $user->applications()
            ->orderByPivot('updated_at', 'desc')
            ->first();

        if ($application) {
            $result = $communications->sendApplicationAccess(
                $user,
                $application,
                $request->user(),
                null,
                'admin_resend'
            );

            $this->audit($request, $user, [
                'mode' => $result['mode'],
                'application_id' => $application->id,
            ]);

            return response()->json([
                'message' => $result['mode'] === 'activation_invite'
                    ? "Novo convite de ativação enviado para {$user->email}."
                    : "E-mail com orientações de acesso enviado para {$user->email}.",
                'mode' => $result['mode'],
                'application' => $application->only(['id', 'name', 'slug']),
            ]);
        }

        if (! $user->email_verified_at) {
            $rawCode = strtoupper(Str::random(6));
            $user->forceFill([
                'verification_code' => Hash::make($rawCode),
                'verification_code_expires_at' => now()->addMinutes(30),
            ])->save();

            Mail::to($user->email)->send(new ResendVerificationCodeMail($rawCode, $user));
            $this->audit($request, $user, ['mode' => 'verification_code']);

            return response()->json([
                'message' => "Novo código de verificação enviado para {$user->email}.",
                'mode' => 'verification_code',
            ]);
        }

        return response()->json([
            'message' => 'Este usuário já confirmou o e-mail e ainda não possui um aplicativo vinculado para receber orientações de acesso.',
        ], 422);
    }

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && (
            $user->hasProfile('Administrador') ||
            $user->hasPermission('ecosystem_manage') ||
            $user->hasPermission('application_manage') ||
            $user->hasPermission('user_management') ||
            $user->hasPermission('permission_management')
        ), 403, 'Usuário sem permissão para administrar o ecossistema Peter Tecnet.');
    }

    private function audit(Request $request, User $user, array $after): void
    {
        EcosystemAuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'user.email_resent',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'before' => null,
            'after' => $after + ['email' => $user->email],
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);
    }
}
