<?php

namespace App\Domain\Leasing\Listeners;

use App\Domain\Documents\Events\DocumentSignatureRecorded;
use Illuminate\Support\Facades\DB;

final class SyncLeaseDocumentSignature
{
    public function handle(DocumentSignatureRecorded $event): void
    {
        $document = DB::table('documents')->where('id', $event->documentId)->first();
        if (! $document || $document->context_type !== 'lease' || $document->document_type !== 'lease_agreement') return;

        $leaseId = (int) $document->context_id;
        $lease = DB::table('leases')->where('app_id', $document->app_id)->where('id', $leaseId)->whereNull('deleted_at')->first();
        if (! $lease) return;

        $party = DB::table('document_parties')->where('id', $event->documentPartyId)->where('document_id', $document->id)->first();
        if (! $party || ! in_array($party->role, ['landlord', 'tenant'], true)) return;

        $version = DB::table('document_versions')->where('document_id', $document->id)->where('version', $document->current_version)->first();
        if (! $version) return;
        $signature = DB::table('document_signatures')->where('document_version_id', $version->id)->where('document_party_id', $party->id)->first();
        if (! $signature) return;

        DB::transaction(function () use ($document, $lease, $leaseId, $party, $signature) {
            DB::table('lease_signatures')->updateOrInsert(
                ['lease_id' => $leaseId, 'party' => $party->role],
                [
                    'app_id' => $document->app_id,
                    'user_id' => $signature->user_id,
                    'signer_name' => $signature->signer_name,
                    'signer_email' => $signature->signer_email,
                    'signer_tax_id' => $signature->signer_tax_id ? mb_substr($signature->signer_tax_id, 0, 32) : null,
                    'signature_type' => $signature->signature_type,
                    'signature_hash' => $signature->signature_hash,
                    'ip_address' => $signature->ip_address,
                    'user_agent' => $signature->user_agent,
                    'signed_at' => $signature->signed_at,
                    'metadata' => json_encode(['document_id' => $document->public_id, 'version' => $document->current_version, 'content_hash' => $signature->content_hash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            if ($document->status === 'signed') {
                DB::table('leases')->where('app_id', $document->app_id)->where('id', $leaseId)->update(['status' => 'active', 'activated_at' => $lease->activated_at ?: now(), 'updated_at' => now()]);
                DB::table('properties')->where('app_id', $document->app_id)->where('id', $lease->property_id)->update(['status' => 'occupied', 'updated_at' => now()]);
            }
        });
    }
}
