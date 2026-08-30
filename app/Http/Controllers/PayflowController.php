<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PayflowController extends Controller
{
    private function ownedEstablishment(int $establishmentId, int $appId): Establishment
    {
        return Establishment::query()
            ->forApplication($appId)
            ->where('id', $establishmentId)
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }

    public function dashboard(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
            'establishment_id' => 'required|integer|exists:establishments,id',
        ]);
        $this->ownedEstablishment((int) $data['establishment_id'], (int) $data['app_id']);
        $scope = ['app_id' => (int) $data['app_id'], 'establishment_id' => (int) $data['establishment_id']];

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
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
            'establishment_id' => 'required|integer|exists:establishments,id',
        ]);
        $this->ownedEstablishment((int) $data['establishment_id'], (int) $data['app_id']);

        return response()->json(['contacts' => DB::table('payflow_contacts')
            ->where('app_id', $data['app_id'])->where('establishment_id', $data['establishment_id'])
            ->latest()->paginate(30)]);
    }

    public function storeContact(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
            'establishment_id' => 'required|integer|exists:establishments,id',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:255',
            'document' => 'nullable|string|max:40',
            'source' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:10000',
        ]);
        $this->ownedEstablishment((int) $data['establishment_id'], (int) $data['app_id']);

        $id = DB::table('payflow_contacts')->insertGetId([
            ...$data,
            'owner_user_id' => Auth::id(),
            'status' => 'lead',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Contato criado com sucesso.', 'contact' => DB::table('payflow_contacts')->find($id)], 201);
    }

    public function opportunities(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
            'establishment_id' => 'required|integer|exists:establishments,id',
        ]);
        $this->ownedEstablishment((int) $data['establishment_id'], (int) $data['app_id']);

        return response()->json(['opportunities' => DB::table('payflow_opportunities as o')
            ->join('payflow_contacts as c', 'c.id', '=', 'o.contact_id')
            ->where('o.app_id', $data['app_id'])->where('o.establishment_id', $data['establishment_id'])
            ->select('o.*', 'c.name as contact_name', 'c.phone as contact_phone')
            ->latest('o.created_at')->paginate(30)]);
    }

    public function storeOpportunity(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
            'establishment_id' => 'required|integer|exists:establishments,id',
            'contact_id' => 'required|integer|exists:payflow_contacts,id',
            'title' => 'required|string|max:255',
            'value' => 'nullable|numeric|min:0',
            'probability' => 'nullable|integer|min:0|max:100',
            'notes' => 'nullable|string|max:10000',
        ]);
        $this->ownedEstablishment((int) $data['establishment_id'], (int) $data['app_id']);
        $contactExists = DB::table('payflow_contacts')->where('id', $data['contact_id'])
            ->where('app_id', $data['app_id'])->where('establishment_id', $data['establishment_id'])->exists();
        abort_unless($contactExists, 422, 'Contato não pertence a este estabelecimento PayFlow.');

        $id = DB::table('payflow_opportunities')->insertGetId([
            ...$data,
            'owner_user_id' => Auth::id(),
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
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
            'establishment_id' => 'required|integer|exists:establishments,id',
            'stage' => ['required', Rule::in(['new', 'qualified', 'proposal', 'payment_pending', 'won', 'lost'])],
        ]);
        $this->ownedEstablishment((int) $data['establishment_id'], (int) $data['app_id']);

        $query = DB::table('payflow_opportunities')->where('id', $id)
            ->where('app_id', $data['app_id'])->where('establishment_id', $data['establishment_id']);
        abort_unless($query->exists(), 404);

        $updates = ['stage' => $data['stage'], 'updated_at' => now()];
        if ($data['stage'] === 'won') $updates['won_at'] = now();
        if ($data['stage'] === 'lost') $updates['lost_at'] = now();
        $query->update($updates);

        return response()->json(['message' => 'Etapa atualizada com sucesso.']);
    }
}
