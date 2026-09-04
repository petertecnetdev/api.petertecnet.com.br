<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Leasing\Services\LeaseReadinessService;
use App\Http\Controllers\Controller;
use App\Services\DocumentDataExtractor;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class LeaseOnboardingController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseReadinessService $readiness,
        private readonly DocumentDataExtractor $extractor,
    ) {}

    public function checklist(Request $request, int $leaseId)
    {
        $lease = $this->accessibleLease($request, $leaseId);
        return response()->json($this->readiness->checklist($lease));
    }

    public function inviteTenant(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        abort_if(empty($lease->tenant_email), 422, 'Informe o e-mail do inquilino antes de enviar o convite.');
        $data = $request->validate(['registration_url' => 'required|url|max:500']);

        $rawToken = Str::random(64);
        $metadata = $this->decode($lease->metadata);
        $metadata['workflow'] = array_merge($metadata['workflow'] ?? [], [
            'stage' => 'awaiting_tenant_registration',
            'tenant_invitation' => [
                'token_hash' => hash('sha256', $rawToken),
                'email' => mb_strtolower((string) $lease->tenant_email),
                'sent_at' => now()->toIso8601String(),
                'expires_at' => now()->addDays(7)->toIso8601String(),
                'consumed_at' => null,
            ],
        ]);

        DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update([
            'status' => 'awaiting_documents',
            'metadata' => $this->json($metadata),
            'updated_at' => now(),
        ]);

        $inviteUrl = rtrim($data['registration_url'], '/')
            .'/?lease='.urlencode((string) $leaseId)
            .'&invite='.urlencode($rawToken)
            .'&email='.urlencode((string) $lease->tenant_email);

        try {
            Mail::raw(
                "Olá {$lease->tenant_name},\n\nVocê recebeu um convite para uma locação. Crie ou acesse sua conta com o e-mail {$lease->tenant_email}, envie seus documentos, confira os dados extraídos, assine o pacote contratual e acompanhe os pagamentos.\n\n{$inviteUrl}\n\nEste convite expira em 7 dias e poderá ser utilizado uma única vez. Se você não reconhece esta solicitação, ignore esta mensagem.",
                fn ($message) => $message->to($lease->tenant_email, $lease->tenant_name)->subject('Convite seguro para sua locação')
            );
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'O convite foi preparado, mas o e-mail não pôde ser enviado.', 'invite_url' => $inviteUrl], 502);
        }

        return response()->json(['ok' => true, 'sent_to' => $lease->tenant_email, 'invite_url' => $inviteUrl, 'stage' => 'awaiting_tenant_registration']);
    }

    public function acceptInvitation(Request $request, int $leaseId)
    {
        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->whereNull('deleted_at')->firstOrFail();
        $data = $request->validate(['token' => 'required|string|min:40|max:128']);
        $metadata = $this->decode($lease->metadata);
        $invite = data_get($metadata, 'workflow.tenant_invitation', []);

        abort_if(empty($invite['token_hash']) || empty($invite['expires_at']), 422, 'Convite não encontrado.');
        abort_if(!empty($invite['consumed_at']), 422, 'Este convite já foi utilizado.');
        abort_if(now()->isAfter($invite['expires_at']), 422, 'Este convite expirou. Solicite um novo convite ao locador.');
        abort_unless(hash_equals((string) $invite['token_hash'], hash('sha256', $data['token'])), 403, 'Convite inválido.');
        abort_unless(strcasecmp((string) $lease->tenant_email, (string) $request->user()->email) === 0, 403, 'Entre com o mesmo e-mail que recebeu o convite.');

        $metadata['workflow']['tenant_invitation']['consumed_at'] = now()->toIso8601String();
        $metadata['workflow']['stage'] = 'awaiting_documents';
        $metadata['workflow']['tenant_registered_at'] = now()->toIso8601String();
        DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update([
            'tenant_user_id' => $request->user()->id,
            'metadata' => $this->json($metadata),
            'status' => 'awaiting_documents',
            'updated_at' => now(),
        ]);

        return app(LeasingController::class)->showLease($request, $leaseId);
    }

    public function configureAgreement(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        $data = $request->validate([
            'collect_deposit' => 'required|boolean',
            'collect_first_rent' => 'required|boolean',
            'additional_charges' => 'nullable|array|max:20',
            'additional_charges.*.description' => 'required|string|max:190',
            'additional_charges.*.amount' => 'required|numeric|min:0.01',
            'additional_charges.*.due_date' => 'nullable|date',
        ]);
        $metadata = $this->decode($lease->metadata);
        $metadata['workflow']['initial_payment'] = [
            'collect_deposit' => $data['collect_deposit'],
            'collect_first_rent' => $data['collect_first_rent'],
            'additional_charges' => $data['additional_charges'] ?? [],
            'configured_at' => now()->toIso8601String(),
        ];
        DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update(['metadata' => $this->json($metadata), 'updated_at' => now()]);
        return response()->json(['ok' => true, 'initial_payment' => $metadata['workflow']['initial_payment']]);
    }

    public function extractDocument(Request $request, int $leaseId, int $documentId)
    {
        $this->accessibleLease($request, $leaseId);
        $document = DB::table('lease_documents')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('id', $documentId)->firstOrFail();
        abort_unless(in_array($document->category, ['identity', 'address', 'income'], true), 422, 'Este tipo de documento não utiliza extração de dados.');

        try {
            $extracted = $this->extractor->extract($document->disk ?: 'local', $document->path, $document->mime_type ?: 'application/octet-stream', $document->category);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $meta = $this->decode($document->metadata ?? null);
        $meta['extraction'] = [
            'status' => 'awaiting_confirmation',
            'data' => $extracted,
            'extracted_at' => now()->toIso8601String(),
            'confirmed_at' => null,
        ];
        DB::table('lease_documents')->where('id', $documentId)->update(['metadata' => $this->json($meta), 'updated_at' => now()]);

        return response()->json($meta['extraction']);
    }

    public function confirmExtraction(Request $request, int $leaseId, int $documentId)
    {
        $lease = $this->accessibleLease($request, $leaseId);
        $document = DB::table('lease_documents')->where('app_id', $this->context->id())->where('lease_id', $leaseId)->where('id', $documentId)->firstOrFail();
        $docMeta = $this->decode($document->metadata ?? null);
        $extracted = data_get($docMeta, 'extraction.data', []);
        abort_if(empty($extracted), 422, 'Execute a extração antes da confirmação.');

        $data = $request->validate([
            'accepted' => 'required|accepted',
            'data' => 'nullable|array',
        ]);
        $confirmed = array_merge($extracted, $data['data'] ?? []);
        $leaseMeta = $this->decode($lease->metadata);
        $profileMap = collect($confirmed)->only([
            'birthdate','birthplace','document_type','document_number','document_issuer','parent_1','parent_2','marital_status','occupation','address','city','state','postal_code'
        ])->filter(fn ($value) => $value !== null && $value !== '')->all();
        $leaseMeta['tenant_profile'] = array_merge($leaseMeta['tenant_profile'] ?? [], $profileMap);
        $leaseMeta['workflow']['stage'] = 'documents_under_review';
        $leaseMeta['workflow']['document_data_confirmed_at'] = now()->toIso8601String();

        $leaseUpdate = ['metadata' => $this->json($leaseMeta), 'updated_at' => now()];
        if (!empty($confirmed['full_name'])) $leaseUpdate['tenant_name'] = $confirmed['full_name'];
        if (!empty($confirmed['tax_id'])) $leaseUpdate['tenant_tax_id'] = $confirmed['tax_id'];
        if (empty($lease->tenant_user_id) && strcasecmp((string) $lease->tenant_email, (string) $request->user()->email) === 0) $leaseUpdate['tenant_user_id'] = $request->user()->id;

        $docMeta['extraction']['status'] = 'confirmed';
        $docMeta['extraction']['data'] = $confirmed;
        $docMeta['extraction']['confirmed_at'] = now()->toIso8601String();
        $docMeta['extraction']['confirmed_by_user_id'] = $request->user()->id;

        DB::transaction(function () use ($leaseId, $documentId, $leaseUpdate, $docMeta) {
            DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update($leaseUpdate);
            DB::table('lease_documents')->where('id', $documentId)->update(['metadata' => $this->json($docMeta), 'status' => 'approved', 'updated_at' => now()]);
        });

        return app(LeasingController::class)->showLease($request, $leaseId);
    }

    public function activateIfReady(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        $checklist = $this->readiness->checklist($lease);
        abort_unless($checklist['ready'], 422, 'A locação ainda possui dados ou documentos obrigatórios pendentes.');
        abort_unless($this->readiness->signaturesComplete($lease), 422, 'As duas partes ainda não assinaram a versão atual.');
        abort_unless($this->readiness->initialPaymentsComplete($lease), 422, 'Os valores iniciais obrigatórios ainda não foram pagos.');

        $metadata = $this->decode($lease->metadata);
        $metadata['workflow']['stage'] = 'active';
        $metadata['workflow']['activated_at'] = now()->toIso8601String();
        DB::transaction(function () use ($lease, $leaseId, $metadata) {
            DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update([
                'status' => 'active', 'metadata' => $this->json($metadata), 'updated_at' => now(),
            ]);
            DB::table('properties')->where('app_id', $this->context->id())->where('id', $lease->property_id)->update(['status' => 'occupied', 'updated_at' => now()]);
        });

        return app(LeasingController::class)->showLease($request, $leaseId);
    }

    private function managedLease(Request $request, int $leaseId): object
    {
        $lease = $this->accessibleLease($request, $leaseId);
        abort_unless((int) $lease->landlord_user_id === (int) $request->user()->id || $this->isAdmin($request), 403);
        return $lease;
    }

    private function accessibleLease(Request $request, int $leaseId): object
    {
        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->whereNull('deleted_at')->firstOrFail();
        $user = $request->user();
        $allowed = (int) $lease->landlord_user_id === (int) $user->id
            || (int) ($lease->tenant_user_id ?? 0) === (int) $user->id
            || (!empty($lease->tenant_email) && strcasecmp((string) $lease->tenant_email, (string) $user->email) === 0)
            || $this->isAdmin($request);
        abort_unless($allowed, 403);
        return $lease;
    }

    private function isAdmin(Request $request): bool
    {
        return method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
    }

    private function decode($value): array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return (array) $value;
        if (!$value) return [];
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function json($value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
