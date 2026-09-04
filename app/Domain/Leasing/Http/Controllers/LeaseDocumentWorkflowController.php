<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Documents\DTOs\DocumentAuditContext;
use App\Domain\Documents\Services\DocumentWorkflowService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class LeaseDocumentWorkflowController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly DocumentWorkflowService $documents,
        private readonly LeasePackageLifecycleController $packageLifecycle,
    ) {}

    public function show(Request $request, int $leaseId)
    {
        $this->leaseForAccess($request, $leaseId);
        $appId = $this->context->id();
        $document = $this->documents->latestForContext($appId, 'lease', $leaseId, 'lease_agreement');

        return response()->json([
            'document' => $document,
            'timeline' => $document ? $this->documents->timelineForApplication($appId, $document->id) : [],
        ]);
    }

    public function proposal(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        $property = $this->property($lease->property_id);
        $appId = $this->context->id();
        $document = $this->documents->createOrRevise(
            $appId,
            'lease',
            $leaseId,
            'lease_proposal',
            'Proposta de locação - '.$property->name,
            $this->proposalText($lease, $property),
            $this->snapshot($lease, $property),
            $this->parties($lease),
            (int) $request->user()->id,
            null,
            $this->auditContext($request),
        );

        return response()->json([
            'document' => $document,
            'timeline' => $this->documents->timelineForApplication($appId, $document->id),
        ], 201);
    }

    public function generate(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        abort_if(in_array($lease->status, ['active', 'ended', 'cancelled'], true), 422, 'Contrato ativo ou encerrado deve ser alterado por aditivo.');

        // Preserve the canonical leasing readiness/package lifecycle. Documents only
        // snapshots its output; it never replaces leasing business gates.
        $this->packageLifecycle->generate($request, $leaseId);
        $appId = $this->context->id();
        $lease = DB::table('leases')->where('app_id', $appId)->where('id', $leaseId)->whereNull('deleted_at')->firstOrFail();
        abort_if(empty($lease->contract_text), 422, 'O ciclo de locação não produziu conteúdo contratual.');
        $property = $this->property($lease->property_id);

        $document = $this->documents->createOrRevise(
            $appId,
            'lease',
            $leaseId,
            'lease_agreement',
            'Contrato de locação - '.$property->name,
            $lease->contract_text,
            $this->snapshot($lease, $property),
            $this->parties($lease),
            (int) $request->user()->id,
            null,
            $this->auditContext($request),
        );

        DB::table('leases')->where('app_id', $appId)->where('id', $leaseId)->update([
            'contract_version' => $document->current_version,
            'status' => 'awaiting_signature',
            'updated_at' => now(),
        ]);

        return response()->json([
            'document' => $document,
            'timeline' => $this->documents->timelineForApplication($appId, $document->id),
        ]);
    }

    public function send(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        abort_if(empty($lease->tenant_email), 422, 'Informe o e-mail do inquilino antes do envio.');
        $appId = $this->context->id();
        $document = $this->documents->latestForContext($appId, 'lease', $leaseId, 'lease_agreement');
        abort_if(! $document, 422, 'Gere o contrato antes de enviar para assinatura.');

        $baseUrl = $this->trustedApplicationUrl();
        $auditContext = $this->auditContext($request);
        $sent = $this->documents->sendForApplication($appId, $document->id, (int) $request->user()->id, $auditContext);
        $tenantRequest = collect($sent['signature_requests'])->first(fn ($item) => $item['party']->role === 'tenant');
        if (! $tenantRequest) {
            $this->documents->reopenForRevisionForApplication($appId, $document->id, (int) $request->user()->id, $auditContext);
            abort(422, 'A parte locatária não foi configurada para assinatura.');
        }

        $link = $baseUrl.'/sign/'.$tenantRequest['token'];
        try {
            Mail::raw($this->invitationText($sent['document'], $link), function ($mail) use ($lease) {
                $mail->to($lease->tenant_email, $lease->tenant_name)->subject('Contrato para assinatura eletrônica');
            });
        } catch (Throwable $e) {
            report($e);
            $this->documents->reopenForRevisionForApplication($appId, $document->id, (int) $request->user()->id, $auditContext);

            return response()->json([
                'message' => 'O contrato não foi enviado. As solicitações de assinatura foram invalidadas com segurança; verifique a configuração de e-mail.',
            ], 502);
        }

        return response()->json([
            'ok' => true,
            'sent_to' => $this->maskEmail($lease->tenant_email),
            'document' => $sent['document'],
        ]);
    }

    public function sign(Request $request, int $leaseId)
    {
        $lease = $this->leaseForAccess($request, $leaseId);
        $data = $request->validate([
            'party' => 'required|in:landlord,tenant',
            'signer_name' => 'required|string|max:190',
            'signer_tax_id' => 'nullable|string|max:32',
            'accepted' => 'required|accepted',
        ]);
        $userId = (int) $request->user()->id;

        if ($data['party'] === 'landlord') {
            abort_unless((int) $lease->landlord_user_id === $userId || $this->isAdmin($request), 403);
        } else {
            $isTenant = (int) $lease->tenant_user_id === $userId
                || (! $lease->tenant_user_id && $lease->tenant_email && strcasecmp($lease->tenant_email, (string) $request->user()->email) === 0);
            abort_unless($isTenant || $this->isAdmin($request), 403);
            if (! $lease->tenant_user_id && ! $this->isAdmin($request)) {
                DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update([
                    'tenant_user_id' => $userId,
                    'updated_at' => now(),
                ]);
            }
        }

        $appId = $this->context->id();
        $document = $this->documents->latestForContext($appId, 'lease', $leaseId, 'lease_agreement');
        abort_if(! $document, 422, 'O contrato ainda não foi gerado.');
        $document = $this->documents->signForApplication($appId, $document->id, $data['party'], [
            'name' => $data['signer_name'],
            'email' => $request->user()->email,
            'tax_id' => $data['signer_tax_id'] ?? null,
        ], $userId, $this->auditContext($request));

        // Deliberately do not activate the lease here. Activation remains behind
        // LeaseOnboardingController::activateIfReady and its operational gates.
        return response()->json([
            'document' => $document,
            'timeline' => $this->documents->timelineForApplication($appId, $document->id),
        ]);
    }

    public function timeline(Request $request, int $leaseId)
    {
        $this->leaseForAccess($request, $leaseId);
        $appId = $this->context->id();
        $document = $this->documents->latestForContext($appId, 'lease', $leaseId, 'lease_agreement');

        return response()->json($document ? $this->documents->timelineForApplication($appId, $document->id) : []);
    }

    public function storeAmendment(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        $data = $request->validate([
            'title' => 'required|string|max:190',
            'changes' => 'required|array|min:1|max:50',
            'changes.*.label' => 'required|string|max:190',
            'changes.*.value' => 'required|string|max:5000',
        ]);
        $appId = $this->context->id();
        $agreement = $this->documents->latestForContext($appId, 'lease', $leaseId, 'lease_agreement');
        abort_if(! $agreement || ! in_array($agreement->status, ['signed', 'active'], true), 422, 'O contrato original precisa estar assinado antes de receber um aditivo.');
        $property = $this->property($lease->property_id);
        $lines = collect($data['changes'])->map(fn ($change, $index) => ($index + 1).'. '.$change['label'].': '.$change['value'])->implode("\n\n");
        $content = "ADITIVO AO CONTRATO DE LOCAÇÃO\n\n{$data['title']}\n\nEste aditivo referencia o contrato {$agreement->public_id}, versão {$agreement->current_version}. Permanecem inalteradas as demais condições não expressamente modificadas abaixo.\n\n{$lines}";
        $payload = array_merge($this->snapshot($lease, $property), [
            'changes' => $data['changes'],
            'parent_document_public_id' => $agreement->public_id,
        ]);

        $document = $this->documents->createOrRevise(
            $appId,
            'lease_amendment',
            $leaseId.'-'.Str::uuid(),
            'lease_amendment',
            $data['title'],
            $content,
            $payload,
            $this->parties($lease),
            (int) $request->user()->id,
            $agreement->id,
            $this->auditContext($request),
        );

        return response()->json([
            'document' => $document,
            'timeline' => $this->documents->timelineForApplication($appId, $document->id),
        ], 201);
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
        return [
            'lease' => $this->decodeObject($lease, ['clauses', 'included_expenses', 'tenant_expenses', 'metadata']),
            'property' => $this->decodeObject($property, ['metadata']),
        ];
    }

    private function proposalText(object $lease, object $property): string
    {
        $rent = number_format((float) $lease->rent_amount, 2, ',', '.');
        $deposit = number_format((float) $lease->deposit_amount, 2, ',', '.');

        return trim("PROPOSTA DE LOCAÇÃO\n\nImóvel: {$property->name}\nFinalidade: {$lease->purpose}\nLocatário: {$lease->tenant_name}\nVigência proposta: {$lease->starts_on} a {$lease->ends_on}\nAluguel mensal: R$ {$rent}\nVencimento: dia {$lease->due_day}\nGarantia: {$lease->guarantee_type}\nCaução: R$ {$deposit}\nReajuste: a cada {$lease->adjustment_frequency_months} meses".($lease->adjustment_index ? " pelo índice {$lease->adjustment_index}" : '').".\n\nEsta proposta consolida as condições comerciais antes da emissão do contrato definitivo.");
    }

    private function invitationText(object $document, string $link): string
    {
        return "Seu contrato está pronto para assinatura eletrônica.\n\nDocumento: {$document->title}\nVersão: {$document->current_version}\nLink seguro: {$link}\n\nA versão enviada está bloqueada. Qualquer alteração posterior exige nova versão e invalida links pendentes, preservando o histórico anterior.";
    }

    private function trustedApplicationUrl(): string
    {
        $url = rtrim((string) DB::table('applications')->where('id', $this->context->id())->value('url'), '/');
        abort_if(! $url || ! filter_var($url, FILTER_VALIDATE_URL), 422, 'Configure uma URL pública válida para a aplicação antes de enviar documentos para assinatura.');
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        abort_if($scheme !== 'https' && ! in_array($host, ['localhost', '127.0.0.1'], true), 422, 'A URL pública da aplicação precisa usar HTTPS.');

        return $url;
    }

    private function property(int $propertyId): object
    {
        return DB::table('properties')->where('app_id', $this->context->id())->where('id', $propertyId)->whereNull('deleted_at')->firstOrFail();
    }

    private function leaseForAccess(Request $request, int $leaseId): object
    {
        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->whereNull('deleted_at')->firstOrFail();
        $userId = (int) $request->user()->id;
        $allowed = (int) $lease->landlord_user_id === $userId
            || (int) $lease->tenant_user_id === $userId
            || ($lease->tenant_email && strcasecmp($lease->tenant_email, (string) $request->user()->email) === 0)
            || $this->isAdmin($request);
        abort_unless($allowed, 403);

        return $lease;
    }

    private function managedLease(Request $request, int $leaseId): object
    {
        $lease = $this->leaseForAccess($request, $leaseId);
        abort_unless((int) $lease->landlord_user_id === (int) $request->user()->id || $this->isAdmin($request), 403);

        return $lease;
    }

    private function isAdmin(Request $request): bool
    {
        return method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
    }

    private function decodeObject(object $row, array $columns): array
    {
        $data = (array) $row;
        foreach ($columns as $column) {
            if (array_key_exists($column, $data) && is_string($data[$column])) {
                $data[$column] = json_decode($data[$column], true);
            }
        }

        return $data;
    }

    private function auditContext(Request $request): DocumentAuditContext
    {
        return new DocumentAuditContext(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            source: 'leasing_http',
            requestId: $request->header('X-Request-Id'),
        );
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, min(2, mb_strlen($local))).'***@'.$domain;
    }
}
