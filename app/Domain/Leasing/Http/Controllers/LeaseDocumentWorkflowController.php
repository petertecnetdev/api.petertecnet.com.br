<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Documents\Services\DocumentWorkflowService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class LeaseDocumentWorkflowController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly DocumentWorkflowService $documents,
        private readonly LeaseContractController $contractBuilder,
    ) {}

    public function show(Request $request, int $leaseId)
    {
        $this->leaseForAccess($request, $leaseId);
        $document = $this->documents->latestForContext($this->context->id(), 'lease', $leaseId, 'lease_agreement');
        return response()->json(['document' => $document, 'timeline' => $document ? $this->documents->timeline($document->id) : []]);
    }

    public function proposal(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId); $property = $this->property($lease->property_id);
        $document = $this->documents->createOrRevise(
            $this->context->id(), 'lease', $leaseId, 'lease_proposal', 'Proposta de locação - '.$property->name,
            $this->proposalText($lease, $property), $this->snapshot($lease, $property), $this->parties($lease), (int) $request->user()->id, null, $request,
        );
        return response()->json(['document' => $document, 'timeline' => $this->documents->timeline($document->id)], 201);
    }

    public function generate(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        abort_if(in_array($lease->status, ['active', 'ended', 'cancelled'], true), 422, 'Contrato ativo ou encerrado deve ser alterado por aditivo.');

        $current = $this->documents->latestForContext($this->context->id(), 'lease', $leaseId, 'lease_agreement');
        if ($current && in_array($current->status, ['awaiting_signatures', 'partially_signed'], true)) {
            $this->documents->reopenForRevision($current->id, (int) $request->user()->id, $request);
        }

        // Reuse the canonical leasing builder already responsible for qualification,
        // clauses and legal text, then snapshot its output in the generic Documents domain.
        $this->contractBuilder->generate($request, $leaseId);
        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->whereNull('deleted_at')->firstOrFail();
        $property = $this->property($lease->property_id);
        abort_if(empty($lease->contract_text), 422, 'O gerador não produziu conteúdo contratual.');

        $document = $this->documents->createOrRevise(
            $this->context->id(), 'lease', $leaseId, 'lease_agreement', 'Contrato de locação - '.$property->name,
            $lease->contract_text, $this->snapshot($lease, $property), $this->parties($lease), (int) $request->user()->id, null, $request,
        );
        DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update(['contract_version' => $document->current_version, 'status' => 'awaiting_signature', 'updated_at' => now()]);
        return response()->json(['document' => $document, 'timeline' => $this->documents->timeline($document->id)]);
    }

    public function send(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        abort_if(empty($lease->tenant_email), 422, 'Informe o e-mail do inquilino antes do envio.');
        $document = $this->documents->latestForContext($this->context->id(), 'lease', $leaseId, 'lease_agreement');
        abort_if(! $document, 422, 'Gere o contrato antes de enviar para assinatura.');

        $sent = $this->documents->send($document->id, (int) $request->user()->id, $request);
        $tenantRequest = collect($sent['signature_requests'])->first(fn ($item) => $item['party']->role === 'tenant');
        abort_if(! $tenantRequest, 422, 'A parte locatária não foi configurada para assinatura.');

        $origin = rtrim((string) $request->headers->get('Origin'), '/');
        $link = $origin ? $origin.'/sign/'.$tenantRequest['token'] : null;
        try {
            Mail::raw($this->invitationText($document, $tenantRequest['token'], $link), function ($mail) use ($lease) {
                $mail->to($lease->tenant_email, $lease->tenant_name)->subject('Contrato para assinatura eletrônica');
            });
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'A versão foi bloqueada, mas o e-mail não pôde ser enviado. Verifique a configuração de e-mail.', 'document' => $sent['document']], 502);
        }

        DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update(['status' => 'awaiting_signature', 'updated_at' => now()]);
        return response()->json(['ok' => true, 'sent_to' => $lease->tenant_email, 'document' => $sent['document']]);
    }

    public function sign(Request $request, int $leaseId)
    {
        $lease = $this->leaseForAccess($request, $leaseId);
        $data = $request->validate(['party' => 'required|in:landlord,tenant', 'signer_name' => 'required|string|max:190', 'signer_tax_id' => 'nullable|string|max:40', 'accepted' => 'required|accepted']);
        $userId = (int) $request->user()->id;

        if ($data['party'] === 'landlord') {
            abort_unless((int) $lease->landlord_user_id === $userId || $this->isAdmin($request), 403);
        } else {
            $tenant = (int) $lease->tenant_user_id === $userId || (! $lease->tenant_user_id && $lease->tenant_email && strcasecmp($lease->tenant_email, (string) $request->user()->email) === 0);
            abort_unless($tenant || $this->isAdmin($request), 403);
            if (! $lease->tenant_user_id && ! $this->isAdmin($request)) DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update(['tenant_user_id' => $userId, 'updated_at' => now()]);
        }

        $document = $this->documents->latestForContext($this->context->id(), 'lease', $leaseId, 'lease_agreement');
        abort_if(! $document, 422, 'O contrato ainda não foi gerado.');
        $document = $this->documents->sign($document->id, $data['party'], ['name' => $data['signer_name'], 'email' => $request->user()->email, 'tax_id' => $data['signer_tax_id'] ?? null], $userId, $request);
        $this->syncLegacySignature($leaseId, $data['party'], $document, $request, $data);
        if ($document->status === 'signed') $this->activateLease($leaseId, $lease);
        return response()->json(['document' => $document, 'timeline' => $this->documents->timeline($document->id)]);
    }

    public function timeline(Request $request, int $leaseId)
    {
        $this->leaseForAccess($request, $leaseId); $document = $this->documents->latestForContext($this->context->id(), 'lease', $leaseId, 'lease_agreement');
        return response()->json($document ? $this->documents->timeline($document->id) : []);
    }

    public function amendments(Request $request, int $leaseId)
    {
        $this->leaseForAccess($request, $leaseId); $agreement = $this->documents->latestForContext($this->context->id(), 'lease', $leaseId, 'lease_agreement');
        if (! $agreement) return response()->json([]);
        return response()->json(DB::table('documents')->where('app_id', $this->context->id())->where('parent_document_id', $agreement->id)->where('document_type', 'lease_amendment')->whereNull('deleted_at')->orderByDesc('id')->get());
    }

    public function storeAmendment(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        $data = $request->validate(['title' => 'required|string|max:190', 'changes' => 'required|array|min:1', 'changes.*.label' => 'required|string|max:190', 'changes.*.value' => 'required|string|max:5000']);
        $agreement = $this->documents->latestForContext($this->context->id(), 'lease', $leaseId, 'lease_agreement');
        abort_if(! $agreement || ! in_array($agreement->status, ['signed', 'active'], true), 422, 'O contrato original precisa estar assinado antes de receber um aditivo.');
        $property = $this->property($lease->property_id);
        $lines = collect($data['changes'])->map(fn ($change, $index) => ($index + 1).'. '.$change['label'].': '.$change['value'])->implode("\n\n");
        $content = "ADITIVO AO CONTRATO DE LOCAÇÃO\n\n{$data['title']}\n\nEste aditivo referencia o contrato {$agreement->public_id}, versão {$agreement->current_version}. Permanecem inalteradas as demais condições não expressamente modificadas abaixo.\n\n{$lines}";
        $payload = array_merge($this->snapshot($lease, $property), ['changes' => $data['changes'], 'parent_document_public_id' => $agreement->public_id]);
        $document = $this->documents->createOrRevise($this->context->id(), 'lease_amendment', $leaseId.'-'.now()->format('YmdHisv'), 'lease_amendment', $data['title'], $content, $payload, $this->parties($lease), (int) $request->user()->id, $agreement->id, $request);
        return response()->json(['document' => $document, 'timeline' => $this->documents->timeline($document->id)], 201);
    }

    private function proposalText(object $lease, object $property): string
    {
        $rent = number_format((float) $lease->rent_amount, 2, ',', '.'); $deposit = number_format((float) $lease->deposit_amount, 2, ',', '.');
        return trim("PROPOSTA DE LOCAÇÃO\n\nImóvel: {$property->name}\nFinalidade: {$lease->purpose}\nLocatário: {$lease->tenant_name}\nVigência proposta: {$lease->starts_on} a {$lease->ends_on}\nAluguel mensal: R$ {$rent}\nVencimento: dia {$lease->due_day}\nGarantia: {$lease->guarantee_type}\nCaução: R$ {$deposit}\nReajuste: a cada {$lease->adjustment_frequency_months} meses".($lease->adjustment_index ? " pelo índice {$lease->adjustment_index}" : '').".\n\nEsta proposta consolida as condições comerciais antes da emissão do contrato definitivo.");
    }

    private function invitationText(object $document, string $token, ?string $link): string
    {
        $text = "Seu contrato está pronto para assinatura eletrônica.\n\nDocumento: {$document->title}\nVersão: {$document->current_version}\n";
        $text .= $link ? "Link seguro: {$link}\n" : "Código seguro de assinatura: {$token}\n";
        return $text."\nA versão enviada está bloqueada. Qualquer alteração posterior exige nova versão, preservando o histórico anterior.";
    }

    private function parties(object $lease): array
    {
        $landlord = DB::table('users')->where('id', $lease->landlord_user_id)->first();
        $landlordName = trim(($landlord->first_name ?? '').' '.($landlord->last_name ?? '')) ?: ($landlord->name ?? 'Locador');
        return [
            ['role' => 'landlord', 'user_id' => $lease->landlord_user_id, 'name' => $landlordName, 'email' => $landlord->email ?? null, 'signing_order' => 1],
            ['role' => 'tenant', 'user_id' => $lease->tenant_user_id, 'name' => $lease->tenant_name, 'email' => $lease->tenant_email, 'tax_id' => $lease->tenant_tax_id, 'signing_order' => 2],
        ];
    }

    private function snapshot(object $lease, object $property): array
    {
        return ['lease' => $this->decodeObject($lease, ['clauses', 'included_expenses', 'tenant_expenses', 'metadata']), 'property' => $this->decodeObject($property, ['metadata'])];
    }

    private function syncLegacySignature(int $leaseId, string $party, object $document, Request $request, array $data): void
    {
        $genericParty = collect($document->parties)->first(fn ($item) => $item->role === $party);
        $signature = collect($document->signatures)->first(fn ($item) => (int) $item->document_party_id === (int) $genericParty?->id);
        if (! $signature) return;
        DB::table('lease_signatures')->updateOrInsert(['app_id' => $this->context->id(), 'lease_id' => $leaseId, 'party' => $party], [
            'user_id' => $request->user()->id, 'signer_name' => $data['signer_name'], 'signer_email' => $request->user()->email, 'signer_tax_id' => $data['signer_tax_id'] ?? null,
            'signature_type' => 'electronic_acknowledgement', 'signature_hash' => $signature->signature_hash, 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
            'signed_at' => $signature->signed_at, 'metadata' => json_encode(['document_id' => $document->public_id, 'version' => $document->current_version, 'content_hash' => $signature->content_hash], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function activateLease(int $leaseId, object $lease): void
    {
        DB::transaction(function () use ($leaseId, $lease) {
            DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update(['status' => 'active', 'activated_at' => now(), 'updated_at' => now()]);
            DB::table('properties')->where('app_id', $this->context->id())->where('id', $lease->property_id)->update(['status' => 'occupied', 'updated_at' => now()]);
        });
    }

    private function leaseForAccess(Request $request, int $leaseId): object
    {
        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->whereNull('deleted_at')->firstOrFail(); $userId = (int) $request->user()->id;
        $allowed = (int) $lease->landlord_user_id === $userId || (int) $lease->tenant_user_id === $userId || ($lease->tenant_email && strcasecmp($lease->tenant_email, (string) $request->user()->email) === 0) || $this->isAdmin($request);
        abort_unless($allowed, 403); return $lease;
    }

    private function managedLease(Request $request, int $leaseId): object
    {
        $lease = $this->leaseForAccess($request, $leaseId); abort_unless((int) $lease->landlord_user_id === (int) $request->user()->id || $this->isAdmin($request), 403); return $lease;
    }

    private function property(int $id): object { return DB::table('properties')->where('app_id', $this->context->id())->where('id', $id)->whereNull('deleted_at')->firstOrFail(); }
    private function decodeObject(object $row, array $columns): array { $copy = (array) $row; foreach ($columns as $column) if (array_key_exists($column, $copy)) $copy[$column] = $this->decode($copy[$column]); return $copy; }
    private function decode(mixed $value): array { if (is_array($value)) return $value; if (! $value) return []; $decoded = json_decode((string) $value, true); return is_array($decoded) ? $decoded : []; }
    private function isAdmin(Request $request): bool { return method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador'); }
}
