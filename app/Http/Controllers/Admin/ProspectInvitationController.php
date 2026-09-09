<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\ProspectInvitationMail;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class ProspectInvitationController extends Controller
{
    private const PERSONAS = [
        'participant',
        'producer',
        'artist',
        'promoter',
        'client',
        'professional',
        'partner',
    ];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'application_id' => ['required', 'integer', 'exists:applications,id'],
            'persona' => ['required', 'string', Rule::in(self::PERSONAS)],
        ]);

        $application = Application::query()->findOrFail($data['application_id']);
        $recipientName = trim((string) ($data['recipient_name'] ?? '')) ?: null;
        $email = strtolower(trim($data['email']));

        try {
            Mail::to($email)->queue(new ProspectInvitationMail(
                $application,
                $data['persona'],
                $recipientName
            ));
        } catch (\Throwable $exception) {
            Log::error('Falha ao enviar convite comercial pelo Admin Center.', [
                'application_id' => $application->id,
                'persona' => $data['persona'],
                'recipient_domain' => str_contains($email, '@') ? substr(strrchr($email, '@'), 1) : null,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível enviar o convite por e-mail neste momento.',
            ], 503);
        }

        Log::info('Convite comercial enviado pelo Admin Center.', [
            'application_id' => $application->id,
            'application_slug' => $application->slug,
            'persona' => $data['persona'],
            'sent_by_user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Convite enviado com sucesso.',
            'delivery' => [
                'email' => $email,
                'application' => $application->only(['id', 'name', 'slug', 'url']),
                'persona' => $data['persona'],
            ],
        ], 201);
    }
}
