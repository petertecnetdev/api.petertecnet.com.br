<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Establishment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        $scope = $this->context($request);

        $won = DB::table('payflow_opportunities')->where($scope)->where('stage', 'won')->sum('value');
        $received = DB::table('payflow_charges')->where($scope)->where('status', 'paid')->sum('amount');
        $pending = DB::table('payflow_charges')->where($scope)->where('status', 'pending')->sum('amount');

        return response()->json([
            'metrics' => [
                'sold' => (float) $won,
                'received' => (float) $received,
                'pending' => (float) $pending,
                'contacts' => DB::table('payflow_contacts')->where($scope)->count(),
                'open_opportunities' => DB::table('payflow_opportunities')->where($scope)->whereNotIn('stage', ['won', 'lost'])->count(),
            ],
            'activities' => DB::table('payflow_agent_activities')->where($scope)->latest('executed_at')->limit(20)->get(),
        ]);
    }

    public function contacts(Request $request)
    {
        $scope = $this->context($request);
        return response()->json([
            'contacts' => DB::table('payflow_contacts')->where($scope)->latest()->paginate(30),
        ]);
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

        return response()->json([
            'message' => 'Contato criado com sucesso.',
            'contact' => DB::table('payflow_contacts')->find($id),
        ], 201);
    }

    public function opportunities(Request $request)
    {
        $scope = $this->context($request);

        return response()->json([
            'opportunities' => DB::table('payflow_opportunities as o')
                ->join('payflow_contacts as c', 'c.id', '=', 'o.contact_id')
                ->where('o.app_id', $scope['app_id'])
                ->where('o.establishment_id', $scope['establishment_id'])
                ->where('o.owner_user_id', $scope['owner_user_id'])
                ->select('o.*', 'c.name as contact_name', 'c.phone as contact_phone')
                ->latest('o.created_at')
                ->paginate(30),
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

        $contactExists = DB::table('payflow_contacts')
            ->where('id', $data['contact_id'])
            ->where($scope)
            ->exists();
        abort_unless($contactExists, 422, 'Contato não pertence a este estabelecimento PayFlow.');

        $id = DB::table('payflow_opportunities')->insertGetId([
            ...$scope,
            ...$data,
            'stage' => 'new',
            'value' => $data['value'] ?? 0,
            'probability' => $data['probability'] ?? 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Oportunidade criada com sucesso.',
            'opportunity' => DB::table('payflow_opportunities')->find($id),
        ], 201);
    }

    public function updateOpportunityStage(Request $request, int $id)
    {
        $scope = $this->context($request);
        $data = $request->validate([
            'stage' => ['required', Rule::in(['new', 'qualified', 'proposal', 'payment_pending', 'won', 'lost'])],
        ]);

        $query = DB::table('payflow_opportunities')->where('id', $id)->where($scope);
        abort_unless($query->exists(), 404);

        $updates = ['stage' => $data['stage'], 'updated_at' => now()];
        if ($data['stage'] === 'won') $updates['won_at'] = now();
        if ($data['stage'] === 'lost') $updates['lost_at'] = now();
        $query->update($updates);

        return response()->json(['message' => 'Etapa atualizada com sucesso.']);
    }
}
