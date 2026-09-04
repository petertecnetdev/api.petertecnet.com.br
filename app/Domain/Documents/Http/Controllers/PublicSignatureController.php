<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\Services\DocumentWorkflowService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PublicSignatureController extends Controller
{
    public function __construct(private readonly DocumentWorkflowService $documents) {}

    public function show(Request $request, string $token)
    {
        $signatureRequest = $this->resolve($token);
        $document = DB::table('documents')->where('id', $signatureRequest->document_id)->whereNull('deleted_at')->firstOrFail();
        $version = DB::table('document_versions')->where('id', $signatureRequest->document_version_id)->firstOrFail();
        $party = DB::table('document_parties')->where('id', $signatureRequest->document_party_id)->firstOrFail();

        if (! $signatureRequest->viewed_at && $signatureRequest->status === 'pending') {
            DB::table('signature_requests')->where('id', $signatureRequest->id)->update(['viewed_at' => now(), 'updated_at' => now()]);
            DB::table('document_audit_events')->insert([
                'document_id' => $document->id, 'document_version_id' => $version->id, 'actor_user_id' => null,
                'event_type' => 'document.viewed', 'description' => 'Documento visualizado por link seguro de assinatura.',
                'metadata' => json_encode(['party_role' => $party->role], JSON_UNESCAPED_UNICODE), 'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return response()->json([
            'request' => ['public_id' => $signatureRequest->public_id, 'status' => $signatureRequest->status, 'expires_at' => $signatureRequest->expires_at, 'signed_at' => $signatureRequest->signed_at],
            'document' => ['public_id' => $document->public_id, 'title' => $document->title, 'document_type' => $document->document_type, 'status' => $document->status, 'version' => $version->version, 'content' => $version->content, 'content_hash' => $version->content_hash],
            'party' => ['role' => $party->role, 'name' => $party->name, 'email' => $this->maskEmail($party->email)],
        ]);
    }

    public function sign(Request $request, string $token)
    {
        $signatureRequest = $this->resolve($token, true);
        $party = DB::table('document_parties')->where('id', $signatureRequest->document_party_id)->firstOrFail();
        $data = $request->validate(['signer_name' => 'required|string|max:190', 'signer_tax_id' => 'nullable|string|max:40', 'accepted' => 'required|accepted']);
        $document = $this->documents->sign($signatureRequest->document_id, $party->role, ['name' => $data['signer_name'], 'email' => $party->email, 'tax_id' => $data['signer_tax_id'] ?? $party->tax_id], null, $request);
        return response()->json(['ok' => true, 'message' => 'Assinatura registrada com integridade vinculada à versão do documento.', 'document' => $document]);
    }

    private function resolve(string $token, bool $mustBePending = false): object
    {
        abort_unless(strlen($token) >= 40, 404);
        $query = DB::table('signature_requests')->where('token_hash', hash('sha256', $token)); if ($mustBePending) $query->where('status', 'pending');
        $signatureRequest = $query->firstOrFail();
        abort_if($signatureRequest->expires_at && now()->greaterThan($signatureRequest->expires_at), 410, 'Este link de assinatura expirou.');
        abort_if($signatureRequest->cancelled_at || $signatureRequest->status === 'cancelled', 410, 'Este link de assinatura foi cancelado.');
        return $signatureRequest;
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) return $email;
        [$local, $domain] = explode('@', $email, 2); $visible = mb_substr($local, 0, min(2, mb_strlen($local)));
        return $visible.str_repeat('*', max(2, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }
}
