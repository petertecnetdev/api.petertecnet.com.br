<?php

namespace App\Domain\Documents\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DocumentWorkflowService
{
    public function createOrRevise(int $appId, string $contextType, string|int $contextId, string $documentType, string $title, string $content, array $payload, array $parties, ?int $actorUserId = null, ?int $parentDocumentId = null, ?Request $request = null): object
    {
        return DB::transaction(function () use ($appId, $contextType, $contextId, $documentType, $title, $content, $payload, $parties, $actorUserId, $parentDocumentId, $request) {
            $document = DB::table('documents')->where('app_id', $appId)->where('context_type', $contextType)->where('context_id', (string) $contextId)->where('document_type', $documentType)->whereNull('deleted_at')->whereIn('status', ['draft', 'review'])->lockForUpdate()->latest('id')->first();
            if (! $document) {
                $documentId = DB::table('documents')->insertGetId([
                    'public_id' => (string) Str::uuid(), 'app_id' => $appId, 'context_type' => $contextType, 'context_id' => (string) $contextId,
                    'parent_document_id' => $parentDocumentId, 'created_by_user_id' => $actorUserId, 'document_type' => $documentType, 'title' => $title,
                    'status' => 'draft', 'current_version' => 0, 'payload' => $this->json($payload), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $document = DB::table('documents')->where('id', $documentId)->lockForUpdate()->first();
                $this->audit($documentId, null, $actorUserId, 'document.created', 'Documento criado.', [], $request);
            }
            $nextVersion = ((int) $document->current_version) + 1; $contentHash = hash('sha256', $content);
            $versionId = DB::table('document_versions')->insertGetId([
                'document_id' => $document->id, 'version' => $nextVersion, 'status' => 'draft', 'content' => $content, 'payload_snapshot' => $this->json($payload),
                'content_hash' => $contentHash, 'is_locked' => false, 'created_by_user_id' => $actorUserId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('documents')->where('id', $document->id)->update(['title' => $title, 'payload' => $this->json($payload), 'current_version' => $nextVersion, 'status' => 'review', 'updated_at' => now()]);
            foreach ($parties as $party) {
                DB::table('document_parties')->updateOrInsert(['document_id' => $document->id, 'role' => $party['role']], [
                    'user_id' => $party['user_id'] ?? null, 'name' => $party['name'], 'email' => $party['email'] ?? null, 'tax_id' => $party['tax_id'] ?? null,
                    'signing_order' => $party['signing_order'] ?? 1, 'must_sign' => $party['must_sign'] ?? true, 'metadata' => $this->json($party['metadata'] ?? null),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit($document->id, $versionId, $actorUserId, 'document.version.created', 'Nova versão da minuta criada.', ['version' => $nextVersion, 'content_hash' => $contentHash], $request);
            return $this->payload($document->id);
        });
    }

    public function reopenForRevision(int $documentId, ?int $actorUserId = null, ?Request $request = null): object
    {
        DB::transaction(function () use ($documentId, $actorUserId, $request) {
            $document = DB::table('documents')->where('id', $documentId)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            abort_if(in_array($document->status, ['signed', 'active', 'cancelled', 'expired'], true), 422, 'Documento concluído deve ser alterado por aditivo.');
            DB::table('signature_requests')->where('document_id', $documentId)->where('status', 'pending')->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
            DB::table('documents')->where('id', $documentId)->update(['status' => 'review', 'updated_at' => now()]);
            $this->audit($documentId, null, $actorUserId, 'document.revision_requested', 'Solicitações pendentes foram invalidadas para criação de nova versão.', [], $request);
        });
        return $this->payload($documentId);
    }

    public function send(int $documentId, ?int $actorUserId = null, ?Request $request = null, int $expiresInDays = 15): array
    {
        return DB::transaction(function () use ($documentId, $actorUserId, $request, $expiresInDays) {
            $document = DB::table('documents')->where('id', $documentId)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            abort_if(in_array($document->status, ['signed', 'active', 'cancelled', 'expired'], true), 422, 'Este documento não pode mais ser enviado para assinatura.');
            $version = DB::table('document_versions')->where('document_id', $documentId)->where('version', $document->current_version)->lockForUpdate()->firstOrFail();
            DB::table('document_versions')->where('id', $version->id)->update(['status' => 'locked', 'is_locked' => true, 'locked_at' => $version->locked_at ?: now(), 'updated_at' => now()]);
            DB::table('signature_requests')->where('document_id', $documentId)->where('status', 'pending')->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
            $requests = []; $parties = DB::table('document_parties')->where('document_id', $documentId)->where('must_sign', true)->orderBy('signing_order')->get();
            abort_if($parties->isEmpty(), 422, 'Informe ao menos uma parte obrigatória para assinatura.');
            foreach ($parties as $party) {
                $token = Str::random(64); $requestId = DB::table('signature_requests')->insertGetId([
                    'public_id' => (string) Str::uuid(), 'document_id' => $documentId, 'document_version_id' => $version->id, 'document_party_id' => $party->id,
                    'token_hash' => hash('sha256', $token), 'status' => 'pending', 'expires_at' => now()->addDays($expiresInDays), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $requests[] = ['id' => $requestId, 'party' => $party, 'token' => $token];
            }
            DB::table('documents')->where('id', $documentId)->update(['status' => 'awaiting_signatures', 'sent_at' => now(), 'updated_at' => now()]);
            $this->audit($documentId, $version->id, $actorUserId, 'document.sent', 'Documento enviado para assinatura.', ['version' => $version->version, 'signature_requests' => count($requests)], $request);
            return ['document' => $this->payload($documentId), 'signature_requests' => $requests];
        });
    }

    public function sign(int $documentId, string $role, array $signer, ?int $userId, ?Request $request = null): object
    {
        return DB::transaction(function () use ($documentId, $role, $signer, $userId, $request) {
            $document = DB::table('documents')->where('id', $documentId)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            abort_unless(in_array($document->status, ['awaiting_signatures', 'partially_signed'], true), 422, 'O documento não está disponível para assinatura.');
            $version = DB::table('document_versions')->where('document_id', $documentId)->where('version', $document->current_version)->where('is_locked', true)->firstOrFail();
            $party = DB::table('document_parties')->where('document_id', $documentId)->where('role', $role)->firstOrFail();
            $existing = DB::table('document_signatures')->where('document_version_id', $version->id)->where('document_party_id', $party->id)->first();
            if ($existing) return $this->payload($documentId);
            $signatureRequest = DB::table('signature_requests')->where('document_id', $documentId)->where('document_version_id', $version->id)->where('document_party_id', $party->id)->where('status', 'pending')->latest('id')->first();
            $signedAt = now(); $signatureHash = hash('sha256', implode('|', [$document->public_id, $version->version, $version->content_hash, $role, $party->id, $userId ?: 'guest', $signedAt->toIso8601String(), $request?->ip() ?: '']));
            DB::table('document_signatures')->insert([
                'document_id' => $documentId, 'document_version_id' => $version->id, 'document_party_id' => $party->id, 'signature_request_id' => $signatureRequest?->id,
                'user_id' => $userId, 'signer_name' => $signer['name'], 'signer_email' => $signer['email'] ?? $party->email, 'signer_tax_id' => $signer['tax_id'] ?? $party->tax_id,
                'signature_type' => 'electronic_acknowledgement', 'signature_hash' => $signatureHash, 'content_hash' => $version->content_hash, 'ip_address' => $request?->ip(),
                'user_agent' => mb_substr((string) ($request?->userAgent() ?? ''), 0, 2000), 'evidence' => $this->json(['accepted' => true, 'role' => $role, 'version' => $version->version]),
                'signed_at' => $signedAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($signatureRequest) DB::table('signature_requests')->where('id', $signatureRequest->id)->update(['status' => 'signed', 'signed_at' => $signedAt, 'updated_at' => now()]);
            $requiredCount = DB::table('document_parties')->where('document_id', $documentId)->where('must_sign', true)->count();
            $signedCount = DB::table('document_signatures')->where('document_version_id', $version->id)->distinct()->count('document_party_id'); $complete = $requiredCount > 0 && $signedCount >= $requiredCount;
            DB::table('documents')->where('id', $documentId)->update(['status' => $complete ? 'signed' : 'partially_signed', 'completed_at' => $complete ? now() : null, 'updated_at' => now()]);
            DB::table('document_versions')->where('id', $version->id)->update(['status' => $complete ? 'signed' : 'locked', 'updated_at' => now()]);
            $this->audit($documentId, $version->id, $userId, 'document.signed', 'Parte assinou a versão imutável do documento.', ['role' => $role, 'signature_hash' => $signatureHash, 'complete' => $complete], $request);
            if ($complete) $this->audit($documentId, $version->id, $userId, 'document.completed', 'Todas as assinaturas obrigatórias foram concluídas.', [], $request);
            return $this->payload($documentId);
        });
    }

    public function timeline(int $documentId): array
    {
        return DB::table('document_audit_events')->where('document_id', $documentId)->orderByDesc('occurred_at')->orderByDesc('id')->get()->map(function ($row) { $row->metadata = $row->metadata ? json_decode($row->metadata, true) : null; return $row; })->all();
    }

    public function payload(int $documentId): object
    {
        $document = DB::table('documents')->where('id', $documentId)->whereNull('deleted_at')->firstOrFail(); $document->payload = $document->payload ? json_decode($document->payload, true) : null;
        $document->versions = DB::table('document_versions')->where('document_id', $documentId)->orderByDesc('version')->get()->map(function ($version) { $version->payload_snapshot = $version->payload_snapshot ? json_decode($version->payload_snapshot, true) : null; return $version; });
        $document->parties = DB::table('document_parties')->where('document_id', $documentId)->orderBy('signing_order')->get()->map(function ($party) { $party->metadata = $party->metadata ? json_decode($party->metadata, true) : null; return $party; });
        $document->signatures = DB::table('document_signatures')->where('document_id', $documentId)->orderBy('signed_at')->get()->map(function ($signature) { $signature->evidence = $signature->evidence ? json_decode($signature->evidence, true) : null; return $signature; });
        return $document;
    }

    public function latestForContext(int $appId, string $contextType, string|int $contextId, ?string $documentType = null): ?object
    {
        $query = DB::table('documents')->where('app_id', $appId)->where('context_type', $contextType)->where('context_id', (string) $contextId)->whereNull('deleted_at'); if ($documentType) $query->where('document_type', $documentType);
        $document = $query->latest('id')->first(); return $document ? $this->payload($document->id) : null;
    }

    private function audit(int $documentId, ?int $versionId, ?int $actorUserId, string $eventType, ?string $description, array $metadata, ?Request $request): void
    {
        DB::table('document_audit_events')->insert(['document_id' => $documentId, 'document_version_id' => $versionId, 'actor_user_id' => $actorUserId, 'event_type' => $eventType, 'description' => $description,
            'metadata' => $this->json($metadata), 'ip_address' => $request?->ip(), 'user_agent' => mb_substr((string) ($request?->userAgent() ?? ''), 0, 2000), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function json(mixed $value): ?string { return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}
