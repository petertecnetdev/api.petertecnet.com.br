<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Establishment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PayflowController extends Controller
{
    private function payflowAppId(): int
    {
        return (int) Application::query()->where('slug', 'payflow')->value('id');
    }

    private function context(Request $request): array
    {
        $appId = $this->payflowAppId();
        abort_if($appId <= 0, 503, 'Aplicação PayFlow não cadastrada.');

        $data = $request->validate([
            'establishment_id' => 'required|integer|exists:establishments,id',
        ]);

        $establishment = Establishment::query()
            ->forApplication($appId)
            ->whereKey((int) $data['establishment_id'])
            ->where('user_id', Auth::id())
            ->firstOrFail();

        return [
            'app_id' => $appId,
            'establishment_id' => (int) $establishment->id,
            'owner_user_id' => (int) Auth::id(),
        ];
    }

    private function tenantScope(array $context): array
    {
        return [
            'app_id' => $context['app_id'],
            'establishment_id' => $context['establishment_id'],
        ];
    }

    private function assertContact(int $contactId, array $scope): void
    {
        abort_unless(
            DB::table('payflow_contacts')->where('id', $contactId)->where($scope)->exists(),
            422,
            'Contato não pertence a este estabelecimento PayFlow.'
        );
    }

    public function contextInfo(Request $request)
    {
        $appId = $this->payflowAppId();
        abort_if($appId <= 0, 503, 'Aplicação PayFlow não cadastrada.');

        $establishments = Establishment::query()
            ->forApplication($appId)
            ->where('user_id', Auth::id())
            ->orderBy('name')
            ->get(['id', 'name', 'fantasy', 'slug', 'app_id']);

        return response()->json([
            'application' => Application::query()->find($appId, ['id', 'name', 'slug', 'url', 'logo']),
            'establishments' => $establishments,
        ]);
    }

    public function dashboard(Request $request)
    {
        $context = $this->context($request);
        $tenant = $this->tenantScope($context);

        $won = DB::table('payflow_opportunities')->where($context)->where('stage', 'won')->sum('value');
        $received = DB::table('payflow_charges')->where($tenant)->where('status', 'paid')->sum('amount');
        $pending = DB::table('payflow_charges')->where($tenant)->where('status', 'pending')->sum('amount');

        return response()->json([
            'metrics' => [
                'sold' => (float) $won,
                'received' => (float) $received,
                'pending' => (float) $pending,
                'contacts' => DB::table('payflow_contacts')->where($context)->count(),
                'open_opportunities' => DB::table('payflow_opportunities')->where($context)->whereNotIn('stage', ['won', 'lost'])->count(),
            ],
            'activities' => DB::table('payflow_agent_activities')->where($tenant)->latest('executed_at')->limit(20)->get(),
        ]);
    }

    public function contacts(Request $request)
    {
        $scope = $this->context($request);
        $q = trim((string) $request->query('q', ''));

        $query = DB::table('payflow_contacts')->where($scope);
        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $like = '%' . $q . '%';
                $builder->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        return response()->json(['contacts' => $query->latest()->paginate(30)]);
    }

    public function storeContact(Request $request)
    {
        $scope = $this->context($request);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:255',
            'document' => 'nullable|string|max:40',
            'source' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:10000',
        ]);

        $id = DB::table('payflow_contacts')->insertGetId([
            ...$scope,
            ...$data,
            'status' => 'lead',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Contato criado com sucesso.', 'contact' => DB::table('payflow_contacts')->find($id)], 201);
    }

    public function updateContact(Request $request, int $id)
    {
        $scope = $this->context($request);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|nullable|string|max:40',
            'email' => 'sometimes|nullable|email|max:255',
            'document' => 'sometimes|nullable|string|max:40',
            'source' => 'sometimes|nullable|string|max:50',
            'status' => ['sometimes', Rule::in(['lead', 'customer', 'inactive'])],
            'notes' => 'sometimes|nullable|string|max:10000',
        ]);

        $query = DB::table('payflow_contacts')->where('id', $id)->where($scope);
        abort_unless($query->exists(), 404, 'Contato não encontrado.');
        $query->update([...$data, 'updated_at' => now()]);

        return response()->json(['message' => 'Contato atualizado com sucesso.', 'contact' => DB::table('payflow_contacts')->find($id)]);
    }

    public function destroyContact(Request $request, int $id)
    {
        $scope = $this->context($request);
        $query = DB::table('payflow_contacts')->where('id', $id)->where($scope);
        abort_unless($query->exists(), 404, 'Contato não encontrado.');
        $query->delete();
        return response()->json(['message' => 'Contato excluído com sucesso.']);
    }

    public function opportunities(Request $request)
    {
        $scope = $this->context($request);
        $stage = $request->query('stage');

        $query = DB::table('payflow_opportunities as o')
            ->join('payflow_contacts as c', 'c.id', '=', 'o.contact_id')
            ->where('o.app_id', $scope['app_id'])
            ->where('o.establishment_id', $scope['establishment_id'])
            ->where('o.owner_user_id', $scope['owner_user_id']);

        if ($stage) $query->where('o.stage', $stage);

        return response()->json([
            'opportunities' => $query->select('o.*', 'c.name as contact_name', 'c.phone as contact_phone')->latest('o.created_at')->paginate(100),
        ]);
    }

    public function storeOpportunity(Request $request)
    {
        $scope = $this->context($request);
        $data = $request->validate([
            'contact_id' => 'required|integer|exists:payflow_contacts,id',
            'title' => 'required|string|max:255',
            'value' => 'nullable|numeric|min:0',
            'probability' => 'nullable|integer|min:0|max:100',
            'notes' => 'nullable|string|max:10000',
        ]);
        $this->assertContact((int) $data['contact_id'], $scope);

        $id = DB::table('payflow_opportunities')->insertGetId([
            ...$scope,
            ...$data,
            'stage' => 'new',
            'value' => $data['value'] ?? 0,
            'probability' => $data['probability'] ?? 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Oportunidade criada com sucesso.', 'opportunity' => DB::table('payflow_opportunities')->find($id)], 201);
    }

    public function updateOpportunityStage(Request $request, int $id)
    {
        $scope = $this->context($request);
        $data = $request->validate([
            'stage' => ['required', Rule::in(['new', 'qualified', 'proposal', 'payment_pending', 'won', 'lost'])],
        ]);

        $query = DB::table('payflow_opportunities')->where('id', $id)->where($scope);
        abort_unless($query->exists(), 404, 'Oportunidade não encontrada.');

        $updates = ['stage' => $data['stage'], 'updated_at' => now()];
        if ($data['stage'] === 'won') $updates['won_at'] = now();
        if ($data['stage'] === 'lost') $updates['lost_at'] = now();
        $query->update($updates);

        return response()->json(['message' => 'Etapa atualizada com sucesso.']);
    }

    public function proposals(Request $request)
    {
        $context = $this->context($request);
        $tenant = $this->tenantScope($context);
        return response()->json([
            'proposals' => DB::table('payflow_proposals as p')
                ->join('payflow_contacts as c', 'c.id', '=', 'p.contact_id')
                ->where('p.app_id', $tenant['app_id'])
                ->where('p.establishment_id', $tenant['establishment_id'])
                ->select('p.*', 'c.name as contact_name')
                ->latest('p.created_at')->paginate(50),
        ]);
    }

    public function storeProposal(Request $request)
    {
        $context = $this->context($request);
        $tenant = $this->tenantScope($context);
        $data = $request->validate([
            'contact_id' => 'required|integer|exists:payflow_contacts,id',
            'opportunity_id' => 'nullable|integer|exists:payflow_opportunities,id',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'expires_at' => 'nullable|date',
        ]);
        $this->assertContact((int) $data['contact_id'], $context);

        $items = collect($data['items'])->map(function ($item) {
            $item['total'] = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
            return $item;
        })->values();
        $subtotal = (float) $items->sum('total');
        $discount = min((float) ($data['discount'] ?? 0), $subtotal);
        $total = $subtotal - $discount;
        $code = 'PF-' . now()->format('ymd') . '-' . strtoupper(Str::random(6));

        $id = DB::table('payflow_proposals')->insertGetId([
            ...$tenant,
            'contact_id' => $data['contact_id'],
            'opportunity_id' => $data['opportunity_id'] ?? null,
            'code' => $code,
            'status' => 'draft',
            'items' => json_encode($items->all(), JSON_UNESCAPED_UNICODE),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'expires_at' => $data['expires_at'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Proposta criada com sucesso.', 'proposal' => DB::table('payflow_proposals')->find($id)], 201);
    }

    public function charges(Request $request)
    {
        $tenant = $this->tenantScope($this->context($request));
        return response()->json([
            'charges' => DB::table('payflow_charges as ch')
                ->join('payflow_contacts as c', 'c.id', '=', 'ch.contact_id')
                ->where('ch.app_id', $tenant['app_id'])
                ->where('ch.establishment_id', $tenant['establishment_id'])
                ->select('ch.*', 'c.name as contact_name')
                ->latest('ch.created_at')->paginate(50),
        ]);
    }

    public function storeCharge(Request $request)
    {
        $context = $this->context($request);
        $tenant = $this->tenantScope($context);
        $data = $request->validate([
            'contact_id' => 'required|integer|exists:payflow_contacts,id',
            'proposal_id' => 'nullable|integer|exists:payflow_proposals,id',
            'amount' => 'required|numeric|min:0.01',
            'due_at' => 'nullable|date',
        ]);
        $this->assertContact((int) $data['contact_id'], $context);

        $id = DB::table('payflow_charges')->insertGetId([
            ...$tenant,
            'contact_id' => $data['contact_id'],
            'proposal_id' => $data['proposal_id'] ?? null,
            'provider' => 'manual',
            'status' => 'pending',
            'amount' => $data['amount'],
            'due_at' => $data['due_at'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Cobrança criada com sucesso.', 'charge' => DB::table('payflow_charges')->find($id)], 201);
    }

    public function markChargePaid(Request $request, int $id)
    {
        $tenant = $this->tenantScope($this->context($request));
        $query = DB::table('payflow_charges')->where('id', $id)->where($tenant);
        abort_unless($query->exists(), 404, 'Cobrança não encontrada.');
        $query->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
        return response()->json(['message' => 'Pagamento confirmado.']);
    }
}
