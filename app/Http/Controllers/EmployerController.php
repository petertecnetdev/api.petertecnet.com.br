<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EmployerController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    protected function messages(): array
    {
        return [
            'user_id.required'          => 'O ID do usuário é obrigatório.',
            'user_id.exists'            => 'O usuário informado não existe.',
            'establishment_id.required' => 'O ID do estabelecimento é obrigatório.',
            'establishment_id.exists'   => 'O estabelecimento informado não existe.',
            'role.string'               => 'A função deve ser uma string válida.',
            'role.max'                  => 'A função deve ter no máximo 255 caracteres.',
            'permissions.array'         => 'As permissões devem ser um array.',
            'permissions.*.string'      => 'Cada permissão deve ser uma string.',
        ];
    }

    public function index(): \Illuminate\Http\JsonResponse
    {
        $this->authorize('viewAny', Employer::class);

        $employers = Employer::with(['user', 'establishment'])
                            ->paginate(10);

        return response()->json(['employers' => $employers], 200);
    }

    public function listByEstablishment(int $establishmentId): \Illuminate\Http\JsonResponse
    {
        $this->authorize('viewAny', Employer::class);

        $list = Employer::with('user')
                        ->where('establishment_id', $establishmentId)
                        ->get();

        return response()->json(['employers' => $list], 200);
    }

    public function listByUser(): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        $list = Employer::with('establishment')
                        ->where('user_id', $user->id)
                        ->get();

        return response()->json(['employers' => $list], 200);
    }

    public function show(int $id): \Illuminate\Http\JsonResponse
    {
        $emp = Employer::with(['user', 'establishment'])
                       ->findOrFail($id);

        $this->authorize('view', $emp);

        return response()->json(['employer' => $emp], 200);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', Employer::class);

        $data = $request->validate([
            'user_id'          => 'required|exists:users,id',
            'establishment_id' => 'required|exists:establishments,id',
            'role'             => 'nullable|string|max:255',
            'permissions'      => 'nullable|array',
            'permissions.*'    => 'string',
        ], $this->messages());

        $emp = new Employer($data);
        $emp->created_by = Auth::id();
        $emp->save();
        $emp->load(['user', 'establishment']);

        return response()->json(['employer' => $emp], 201);
    }

    public function update(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $emp = Employer::findOrFail($id);

        $this->authorize('update', $emp);

        $data = $request->validate([
            'role'             => 'nullable|string|max:255',
            'permissions'      => 'nullable|array',
            'permissions.*'    => 'string',
        ], $this->messages());

        $emp->fill($data);
        $emp->updated_by = Auth::id();
        $emp->save();
        $emp->load(['user', 'establishment']);

        return response()->json(['employer' => $emp], 200);
    }

    public function destroy(int $id): \Illuminate\Http\JsonResponse
    {
        $emp = Employer::findOrFail($id);

        $this->authorize('delete', $emp);

        $emp->delete();

        return response()->json(['message' => 'Funcionário removido com sucesso.'], 200);
    }
}
