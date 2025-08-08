<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use App\Models\Establishment;
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
            'user_id.required'     => 'O ID do usuário é obrigatório.',
            'user_id.integer'      => 'O ID do usuário deve ser um número inteiro.',
            'user_id.exists'       => 'O usuário informado não existe.',
            'role.required'        => 'O cargo (role) é obrigatório.',
            'role.string'          => 'O cargo deve ser uma string.',
            'role.max'             => 'O cargo deve ter no máximo 50 caracteres.',
            'permissions.array'    => 'As permissões devem ser um array.',
            'permissions.*.string' => 'Cada permissão deve ser uma string.',
        ];
    }

    /**
     * Lista todos os colaboradores de um estabelecimento do proprietário autenticado.
     */
    public function list(Request $request, $establishment_id)
    {
        try {
            if (!Auth::check()) {
                Log::warning('Tentativa de listagem sem autenticação.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            $establishment = Establishment::find($establishment_id);

            if (!$establishment || $establishment->user_id !== $user->id) {
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            $employers = Employer::with('user')
                ->where('establishment_id', $establishment_id)
                ->get();

            return response()->json([
                'message'       => 'Colaboradores listados com sucesso.',
                'employers'     => $employers,
                'establishment' => $establishment,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao listar colaboradores: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao listar colaboradores.'], 500);
        }
    }

    /**
     * Cadastra um colaborador em um estabelecimento.
     */
    public function store(Request $request, $establishment_id)
    {
        try {
            if (!Auth::check()) {
                Log::warning('Tentativa de cadastro de colaborador sem autenticação.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            $establishment = Establishment::find($establishment_id);

            if (!$establishment || $establishment->user_id !== $user->id) {
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            $data = $request->validate([
                'user_id'     => 'required|integer|exists:users,id',
                'role'        => 'required|string|max:50',
                'permissions' => 'nullable|array',
                'permissions.*' => 'string',
            ], $this->getValidationMessages());

            // evita duplicação
            if (Employer::where('establishment_id', $establishment_id)
                    ->where('user_id', $data['user_id'])
                    ->exists()
            ) {
                return response()->json([
                    'error' => 'Este usuário já é colaborador deste estabelecimento.'
                ], 409);
            }

            $employer = Employer::create([
                'user_id'          => $data['user_id'],
                'establishment_id' => $establishment_id,
                'role'             => $data['role'],
                'permissions'      => $data['permissions'] ?? [],
                'created_by'       => $user->id,
                'updated_by'       => $user->id,
            ]);

            $employer->load('user');

            return response()->json([
                'message'       => 'Colaborador adicionado com sucesso.',
                'employer'      => $employer,
                'establishment' => $establishment,
            ], 201);

        } catch (ValidationException $ve) {
            Log::error('Erro de validação ao cadastrar colaborador.', ['errors' => $ve->errors()]);
            return response()->json(['errors' => $ve->errors()], 422);

        } catch (\Exception $e) {
            Log::error('Erro ao cadastrar colaborador: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao cadastrar colaborador.'], 500);
        }
    }
}
