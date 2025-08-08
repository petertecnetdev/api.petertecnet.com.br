<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use App\Mail\NewEmployerCollaborator;
use App\Mail\OwnerNotifiedNewCollaborator;

class EmployerController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    protected function getValidationMessages(): array
    {
        return [
            'email.required'       => 'O email do usuário é obrigatório.',
            'email.email'          => 'O email fornecido não é válido.',
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
            $est = Establishment::find($establishment_id);
            if (!$est || $est->user_id !== $user->id) {
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            $employers = Employer::with('user')
                ->where('establishment_id', $establishment_id)
                ->get();

            return response()->json([
                'message'       => 'Colaboradores listados com sucesso.',
                'employers'     => $employers,
                'establishment' => $est,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao listar colaboradores: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao listar colaboradores.'], 500);
        }
    }

    /**
     * Cadastra um colaborador em um estabelecimento via email.
     * Envia email para o colaborador e para o proprietário.
     */
    public function store(Request $request, $establishment_id)
    {
        try {
            if (!Auth::check()) {
                Log::warning('Tentativa de cadastro sem autenticação.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            $est = Establishment::find($establishment_id);
            if (!$est || $est->user_id !== $user->id) {
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            $data = $request->validate([
                'email'       => 'required|email',
                'role'        => 'required|string|max:50',
                'permissions' => 'nullable|array',
                'permissions.*'=> 'string',
            ], $this->getValidationMessages());

            // busca usuário por email
            $targetUser = User::where('email', $data['email'])->first();
            if (!$targetUser) {
                return response()->json(['error' => 'Usuário com este email não encontrado.'], 404);
            }

            // evita duplicação
            if (Employer::where('establishment_id', $establishment_id)
                    ->where('user_id', $targetUser->id)
                    ->exists()
            ) {
                return response()->json([
                    'error' => 'Este usuário já é colaborador deste estabelecimento.'
                ], 409);
            }

            // cria vínculo
            $emp = Employer::create([
                'user_id'          => $targetUser->id,
                'establishment_id' => $establishment_id,
                'role'             => $data['role'],
                'permissions'      => $data['permissions'] ?? [],
                'created_by'       => $user->id,
                'updated_by'       => $user->id,
            ]);
            $emp->load('user');

            // dispara emails
            Mail::to($targetUser->email)
                ->send(new NewEmployerCollaborator($est, $emp));
            Mail::to($user->email)
                ->send(new OwnerNotifiedNewCollaborator($est, $emp));

            return response()->json([
                'message'       => 'Colaborador adicionado com sucesso.',
                'employer'      => $emp,
                'establishment' => $est,
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
