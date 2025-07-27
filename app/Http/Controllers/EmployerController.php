<?php

// app/Http/Controllers/EmployerController.php
namespace App\Http\Controllers;

use App\Models\Employer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EmployerController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    protected function getValidationMessages(): array
    {
        return [
            'user_id.required'          => 'O ID do usuário é obrigatório.',
            'user_id.exists'            => 'O usuário informado não existe.',
            'establishment_id.required' => 'O ID do estabelecimento é obrigatório.',
            'establishment_id.exists'   => 'O estabelecimento informado não existe.',
            'role.string'               => 'A função deve ser uma string válida.',
            'permissions.json'          => 'As permissões devem estar em formato JSON.',
        ];
    }

    public function index(): \Illuminate\Http\JsonResponse
    {
        $this->authorize('viewAny', Employer::class);
        $employers = Employer::with(['user', 'establishment'])->paginate(10);
        return response()->json(['employers' => $employers], 200);
    }

    public function listByEstablishment($establishmentId): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view', Employer::class);
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

    public function show($id): \Illuminate\Http\JsonResponse
    {
        $employer = Employer::with(['user', 'establishment'])->findOrFail($id);
        $this->authorize('view', $employer);
        return response()->json(['employer' => $employer], 200);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        $validated = $request->validate([
            'user_id'          => 'required|exists:users,id',
            'establishment_id' => 'required|exists:establishments,id',
            'role'             => 'nullable|string|max:255',
            'permissions'      => 'nullable|json',
        ], $this->getValidationMessages());

        try {
            $emp = new Employer($validated);
            $emp->created_by = $user->id;
            $emp->save();
            return response()->json(['employer' => $emp], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao criar employer: '.$e->getMessage());
            return response()->json(['error' => 'Erro ao criar registro.'], 500);
        }
    }

    public function update(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        $emp = Employer::findOrFail($id);
        $this->authorize('update', $emp);

        $validated = $request->validate([
            'role'        => 'nullable|string|max:255',
            'permissions' => 'nullable|json',
        ], $this->getValidationMessages());

        try {
            $emp->fill($validated);
            $emp->updated_by = $user->id;
            $emp->save();
            return response()->json(['employer' => $emp], 200);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar employer: '.$e->getMessage());
            return response()->json(['error' => 'Erro ao atualizar registro.'], 500);
        }
    }

    public function destroy($id): \Illuminate\Http\JsonResponse
    {
        $emp = Employer::findOrFail($id);
        $this->authorize('delete', $emp);

        try {
            $emp->delete();
            return response()->json(['message' => 'Registro excluído.'], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao excluir employer: '.$e->getMessage());
            return response()->json(['error' => 'Erro ao excluir registro.'], 500);
        }
    }
}
