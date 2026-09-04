<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\DTOs\DocumentAuditContext;
use App\Domain\Documents\Services\DocumentWorkflowService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class PublicSignatureController extends Controller
{
    public function __construct(private readonly DocumentWorkflowService $documents) {}

    public function show(Request $request, string $token)
    {
        $payload = $this->documents->publicSignaturePayload($token, $this->auditContext($request));
        $payload['party']['email'] = $this->maskEmail($payload['party']['email'] ?? null);

        return response()->json($payload);
    }

    public function sign(Request $request, string $token)
    {
        $data = $request->validate([
            'signer_name' => 'required|string|max:190',
            'signer_tax_id' => 'nullable|string|max:40',
            'accepted' => 'required|accepted',
        ]);

        $document = $this->documents->signByToken($token, [
            'name' => $data['signer_name'],
            'tax_id' => $data['signer_tax_id'] ?? null,
        ], $this->auditContext($request));

        return response()->json([
            'ok' => true,
            'message' => 'Assinatura registrada com integridade vinculada à versão do documento.',
            'document' => $document,
        ]);
    }

    private function auditContext(Request $request): DocumentAuditContext
    {
        return new DocumentAuditContext(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            source: 'public_signature_http',
            requestId: $request->header('X-Request-Id'),
        );
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.str_repeat('*', max(2, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }
}
