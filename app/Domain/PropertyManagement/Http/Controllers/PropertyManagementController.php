<?php

namespace App\Domain\PropertyManagement\Http\Controllers;

use App\Domain\PropertyManagement\Services\LeaseAgreementService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PropertyManagementController extends Controller
{
    public function __construct(private readonly LeaseAgreementService $agreements) {}

    private function app(Request $request): string
    {
        return strtolower((string) $request->route('application'));
    }

    private function agreementForUser(Request $request, int $id): object
    {
        $user = $request->user();
        $agreement = DB::table('lease_agreements')
            ->where('id', $id)
            ->where('application_slug', $this->app($request))
            ->first();

        abort_unless($agreement, 404, 'Contrato não encontrado.');

        $allowed = (int) $agreement->landlord_user_id === (int) $user->id
            || (int) $agreement->tenant_user_id === (int) $user->id
            || mb_strtolower($agreement->tenant_email) === mb_strtolower((string) $user->email);

        abort_unless($allowed, 403, 'Você não tem acesso a este contrato.');
        return $agreement;
    }

    public function dashboard(Request $request): JsonResponse
    {
        $app = $this->app($request);
        $userId = (int) $request->user()->id;
        $agreementIds = DB::table('lease_agreements')
            ->where('application_slug', $app)
            ->where('landlord_user_id', $userId)
            ->pluck('id');
        $today = now()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        return response()->json([
            'stats' => [
                'properties' => DB::table('managed_properties')->where('application_slug', $app)->where('owner_user_id', $userId)->count(),
                'active_agreements' => DB::table('lease_agreements')->whereIn('id', $agreementIds)->where('status', 'active')->count(),
                'due_this_month' => (float) DB::table('lease_receivables')->whereIn('agreement_id', $agreementIds)->whereBetween('due_date', [$today, $monthEnd])->whereIn('status', ['pending', 'overdue'])->sum('amount'),
                'overdue_amount' => (float) DB::table('lease_receivables')->whereIn('agreement_id', $agreementIds)->where('due_date', '<', $today)->where('status', '!=', 'paid')->sum('amount'),
            ],
            'recent_receivables' => DB::table('lease_receivables')->whereIn('agreement_id', $agreementIds)->orderBy('due_date')->limit(8)->get(),
        ]);
    }

    public function properties(Request $request): JsonResponse
    {
        $rows = DB::table('managed_properties as p')
            ->where('p.application_slug', $this->app($request))
            ->where('p.owner_user_id', $request->user()->id)
            ->select('p.*')
            ->selectSub(fn ($q) => $q->from('lease_agreements as a')->selectRaw('count(*)')->whereColumn('a.property_id', 'p.id')->whereIn('a.status', ['active', 'signed']), 'active_agreements_count')
            ->latest('p.id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function storeProperty(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'property_type' => ['required', 'string', 'max:50'],
            'usage_type' => ['required', Rule::in(['residential', 'commercial'])],
            'address' => ['required', 'string', 'max:255'],
            'address_number' => ['nullable', 'string', 'max:40'],
            'complement' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'metadata' => ['nullable', 'array'],
        ]);

        $id = DB::table('managed_properties')->insertGetId([
            ...$data,
            'application_slug' => $this->app($request),
            'owner_user_id' => $request->user()->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => DB::table('managed_properties')->find($id)], 201);
    }

    public function agreements(Request $request): JsonResponse
    {
        $app = $this->app($request);
        $user = $request->user();
        $rows = DB::table('lease_agreements as a')
            ->join('managed_properties as p', 'p.id', '=', 'a.property_id')
            ->where('a.application_slug', $app)
            ->where(function ($q) use ($user) {
                $q->where('a.landlord_user_id', $user->id)
                    ->orWhere('a.tenant_user_id', $user->id)
                    ->orWhereRaw('LOWER(a.tenant_email) = ?', [mb_strtolower((string) $user->email)]);
            })
            ->select('a.*', 'p.title as property_title')
            ->latest('a.id')
            ->get()
            ->map(function ($row) {
                $row->property = ['id' => $row->property_id, 'title' => $row->property_title];
                unset($row->property_title);
                return $row;
            });

        return response()->json(['data' => $rows]);
    }

    public function showAgreement(Request $request, int $id): JsonResponse
    {
        $agreement = $this->agreementForUser($request, $id);
        return response()->json([
            'data' => $agreement,
            'signatures' => DB::table('agreement_signatures')->where('agreement_id', $id)->get(),
            'obligations' => DB::table('lease_obligations')->where('agreement_id', $id)->get(),
        ]);
    }

    public function storeAgreement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'property_id' => ['required', 'integer'],
            'tenant_name' => ['required', 'string', 'max:180'],
            'tenant_email' => ['required', 'email', 'max:180'],
            'tenant_cpf' => ['nullable', 'string', 'max:20'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'rent_amount' => ['required', 'numeric', 'min:0.01'],
            'due_day' => ['required', 'integer', 'between:1,28'],
            'guarantee_type' => ['required', Rule::in(['none', 'cash_deposit', 'guarantor', 'insurance', 'investment_fiduciary'])],
            'security_rent_multiplier' => ['nullable', 'integer', 'between:0,3'],
            'included_charges' => ['nullable', 'array'],
            'included_charges.*' => [Rule::in(['water', 'electricity', 'iptu', 'internet', 'condominium'])],
            'clauses' => ['nullable', 'array'],
            'clauses.*' => ['string', 'max:3000'],
            'terms' => ['nullable', 'array'],
        ]);

        $property = DB::table('managed_properties')
            ->where('id', $data['property_id'])
            ->where('application_slug', $this->app($request))
            ->where('owner_user_id', $request->user()->id)
            ->first();
        abort_unless($property, 422, 'Imóvel inválido ou não pertence a esta conta.');

        $data = $this->agreements->validateGuarantee($data);
        $tenant = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($data['tenant_email'])])->first();
        $included = $data['included_charges'] ?? [];
        $clauses = $data['clauses'] ?? [];
        $terms = $data['terms'] ?? [];

        $id = DB::transaction(function () use ($request, $data, $tenant, $included, $clauses, $terms) {
            $id = DB::table('lease_agreements')->insertGetId([
                'application_slug' => $this->app($request),
                'property_id' => $data['property_id'],
                'landlord_user_id' => $request->user()->id,
                'tenant_user_id' => $tenant?->id,
                'tenant_name' => $data['tenant_name'],
                'tenant_email' => mb_strtolower($data['tenant_email']),
                'tenant_cpf' => $data['tenant_cpf'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'rent_amount' => $data['rent_amount'],
                'due_day' => $data['due_day'],
                'guarantee_type' => $data['guarantee_type'],
                'security_rent_multiplier' => $data['security_rent_multiplier'],
                'security_amount' => $data['security_amount'],
                'included_charges' => json_encode($included),
                'clauses' => json_encode($clauses),
                'terms' => json_encode($terms),
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->agreements->syncDefaultObligations($id, $included);
            return $id;
        });

        return response()->json(['data' => DB::table('lease_agreements')->find($id)], 201);
    }

    public function sign(Request $request, int $id): JsonResponse
    {
        $agreement = $this->agreementForUser($request, $id);
        $user = $request->user();
        $data = $request->validate([
            'accepted' => ['required', 'accepted'],
            'signer_name' => ['required', 'string', 'max:180'],
        ]);
        $role = (int) $agreement->landlord_user_id === (int) $user->id ? 'landlord' : 'tenant';
        $timestamp = now();
        $hash = hash('sha256', implode('|', [
            $agreement->id, $agreement->property_id, $agreement->rent_amount,
            $agreement->start_date, $agreement->end_date, $user->id, $role,
            $timestamp->toIso8601String(), $request->ip(),
        ]));

        DB::table('agreement_signatures')->updateOrInsert(
            ['agreement_id' => $agreement->id, 'signer_role' => $role],
            [
                'signer_user_id' => $user->id,
                'signer_name' => $data['signer_name'],
                'signer_email' => $user->email,
                'method' => 'electronic_acceptance',
                'evidence_hash' => $hash,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'signed_at' => $timestamp,
                'metadata' => json_encode(['accepted' => true]),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );

        if ($role === 'tenant' && ! $agreement->tenant_user_id) {
            DB::table('lease_agreements')->where('id', $id)->update(['tenant_user_id' => $user->id, 'updated_at' => now()]);
        }

        $roles = DB::table('agreement_signatures')->where('agreement_id', $id)->pluck('signer_role')->all();
        if (in_array('landlord', $roles, true) && in_array('tenant', $roles, true)) {
            DB::table('lease_agreements')->where('id', $id)->update(['status' => 'active', 'activated_at' => now(), 'updated_at' => now()]);
            $this->agreements->generateRentReceivables(DB::table('lease_agreements')->find($id));
        } else {
            DB::table('lease_agreements')->where('id', $id)->update(['status' => 'partially_signed', 'updated_at' => now()]);
        }

        return response()->json([
            'message' => 'Assinatura registrada com trilha de evidências.',
            'evidence_hash' => $hash,
            'status' => DB::table('lease_agreements')->where('id', $id)->value('status'),
        ]);
    }

    public function contractPdf(Request $request, int $id)
    {
        $a = $this->agreementForUser($request, $id);
        $p = DB::table('managed_properties')->find($a->property_id);
        $landlord = User::find($a->landlord_user_id);
        $clauses = json_decode($a->clauses ?: '[]', true) ?: [];
        $included = json_decode($a->included_charges ?: '[]', true) ?: [];
        $guarantee = match ($a->guarantee_type) {
            'cash_deposit' => 'Caução em dinheiro de ' . $a->security_rent_multiplier . ' aluguel(is), total de R$ ' . number_format($a->security_amount, 2, ',', '.'),
            'guarantor' => 'Fiança',
            'insurance' => 'Seguro-fiança',
            'investment_fiduciary' => 'Cessão fiduciária de quotas de fundo de investimento',
            default => 'Sem garantia locatícia',
        };
        $extra = '';
        foreach ($clauses as $i => $clause) {
            $extra .= '<p><strong>Cláusula adicional ' . ($i + 1) . '.</strong> ' . e($clause) . '</p>';
        }
        $html = '<html><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;line-height:1.55;color:#222}h1{text-align:center;font-size:18px;margin-bottom:24px}h2{font-size:13px;margin-top:18px}p{text-align:justify}.box{background:#f3f6f7;padding:10px;border-radius:6px}</style><body>'
            . '<h1>CONTRATO DE LOCAÇÃO DE IMÓVEL</h1>'
            . '<p><strong>LOCADOR:</strong> ' . e(trim(($landlord->first_name ?? '') . ' ' . ($landlord->last_name ?? ''))) . ' — ' . e($landlord->email ?? '') . '.</p>'
            . '<p><strong>LOCATÁRIO:</strong> ' . e($a->tenant_name) . ' — ' . e($a->tenant_email) . '.</p>'
            . '<p><strong>IMÓVEL:</strong> ' . e($p->title) . ', ' . e($p->address) . ', ' . e($p->city) . '/' . e($p->state) . '. Uso ' . e($p->usage_type) . '.</p>'
            . '<h2>1. PRAZO E ALUGUEL</h2><p>A locação vigorará de ' . date('d/m/Y', strtotime($a->start_date)) . ' a ' . date('d/m/Y', strtotime($a->end_date)) . ', pelo aluguel mensal de R$ ' . number_format($a->rent_amount, 2, ',', '.') . ', com vencimento no dia ' . $a->due_day . '.</p>'
            . '<h2>2. GARANTIA</h2><p>' . e($guarantee) . '.</p>'
            . '<h2>3. ENCARGOS E SERVIÇOS</h2><p>Itens declarados como incluídos no aluguel: ' . e($included ? implode(', ', $included) : 'nenhum') . '. As responsabilidades detalhadas ficam registradas no quadro de obrigações da locação.</p>'
            . '<h2>4. CONSERVAÇÃO E USO</h2><p>O locatário compromete-se a utilizar o imóvel conforme a finalidade contratada, preservá-lo e comunicar ocorrências relevantes ao locador.</p>'
            . $extra
            . '<div class="box"><strong>Assinatura eletrônica:</strong> este documento pode ser assinado eletronicamente no sistema, que registra identidade autenticada, data, IP, agente do dispositivo e hash de evidência. Recomenda-se revisão jurídica das cláusulas personalizadas antes da celebração.</div></body></html>';

        return Pdf::loadHTML($html)->setPaper('a4')->download('contrato-locacao-' . $id . '.pdf');
    }

    public function receivables(Request $request): JsonResponse
    {
        $ids = DB::table('lease_agreements')
            ->where('application_slug', $this->app($request))
            ->where(function ($q) use ($request) {
                $q->where('landlord_user_id', $request->user()->id)->orWhere('tenant_user_id', $request->user()->id);
            })
            ->pluck('id');

        DB::table('lease_receivables')->whereIn('agreement_id', $ids)->where('status', 'pending')->where('due_date', '<', now()->toDateString())->update(['status' => 'overdue', 'updated_at' => now()]);
        return response()->json(['data' => DB::table('lease_receivables')->whereIn('agreement_id', $ids)->orderBy('due_date')->get()]);
    }

    public function documents(Request $request): JsonResponse
    {
        $query = DB::table('property_documents')
            ->where('application_slug', $this->app($request))
            ->where(function ($q) use ($request) {
                $q->where('owner_user_id', $request->user()->id)->orWhere('subject_user_id', $request->user()->id);
            });

        if ($request->filled('agreement_id')) {
            $agreement = $this->agreementForUser($request, (int) $request->integer('agreement_id'));
            $query->where('agreement_id', $agreement->id);
        }

        return response()->json([
            'data' => $query->latest('id')->get()->map(function ($document) {
                $document->storage_path = null;
                return $document;
            }),
        ]);
    }

    public function storeDocument(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agreement_id' => ['required', 'integer'],
            'category' => ['required', 'string', 'max:60'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);
        $agreement = $this->agreementForUser($request, (int) $data['agreement_id']);
        $file = $request->file('file');
        $safeName = bin2hex(random_bytes(16)) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('private/property-management/' . $agreement->id, $safeName, 'local');

        $id = DB::table('property_documents')->insertGetId([
            'application_slug' => $this->app($request),
            'owner_user_id' => $agreement->landlord_user_id,
            'agreement_id' => $agreement->id,
            'property_id' => $agreement->property_id,
            'subject_user_id' => $request->user()->id,
            'category' => $data['category'],
            'name' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => ['id' => $id, 'name' => $file->getClientOriginalName(), 'category' => $data['category'], 'status' => 'received'],
        ], 201);
    }
}
