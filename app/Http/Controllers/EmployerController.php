<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
<<<<<<< HEAD
use Illuminate\Validation\ValidationException;
=======
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use App\Mail\NewEmployerCollaborator;
use App\Mail\OwnerNotifiedNewCollaborator;
>>>>>>> staging

class EmployerController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    protected function getValidationMessages(): array
    {
        return [
<<<<<<< HEAD
            'user_id.required'     => 'O ID do usuário é obrigatório.',
            'user_id.integer'      => 'O ID do usuário deve ser um número inteiro.',
            'user_id.exists'       => 'O usuário informado não existe.',
            'role.required'        => 'O cargo (role) é obrigatório.',
            'role.string'          => 'O cargo deve ser uma string.',
            'role.max'             => 'O cargo deve ter no máximo 50 caracteres.',
            'permissions.array'    => 'As permissões devem ser um array.',
            'permissions.*.string' => 'Cada permissão deve ser uma string.',
=======
            'email.required'      => 'O email do usuário é obrigatório.',
            'email.email'         => 'O email fornecido não é válido.',
            'role.required'       => 'O cargo (role) é obrigatório.',
            'role.string'         => 'O cargo deve ser uma string.',
            'role.max'            => 'O cargo deve ter no máximo 50 caracteres.',
            'permissions.array'   => 'As permissões devem ser um array.',
            'permissions.*.string'=> 'Cada permissão deve ser uma string.',
>>>>>>> staging
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
<<<<<<< HEAD
            $establishment = Establishment::find($establishment_id);

            if (!$establishment || $establishment->user_id !== $user->id) {
=======
            $est = Establishment::find($establishment_id);
            if (!$est || $est->user_id !== $user->id) {
>>>>>>> staging
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            $employers = Employer::with('user')
                ->where('establishment_id', $establishment_id)
                ->get();

            return response()->json([
                'message'       => 'Colaboradores listados com sucesso.',
                'employers'     => $employers,
<<<<<<< HEAD
                'establishment' => $establishment,
=======
                'establishment' => $est,
>>>>>>> staging
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao listar colaboradores: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao listar colaboradores.'], 500);
        }
    }

    /**
<<<<<<< HEAD
     * Cadastra um colaborador em um estabelecimento.
=======
     * Cadastra um colaborador em um estabelecimento, por email.
     * Envia email para o colaborador e para o proprietário.
>>>>>>> staging
     */
    public function store(Request $request, $establishment_id)
    {
        try {
            if (!Auth::check()) {
<<<<<<< HEAD
                Log::warning('Tentativa de cadastro de colaborador sem autenticação.');
=======
                Log::warning('Tentativa de cadastro sem autenticação.');
>>>>>>> staging
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
<<<<<<< HEAD
            $establishment = Establishment::find($establishment_id);

            if (!$establishment || $establishment->user_id !== $user->id) {
=======
            $est = Establishment::find($establishment_id);
            if (!$est || $est->user_id !== $user->id) {
>>>>>>> staging
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            $data = $request->validate([
<<<<<<< HEAD
                'user_id'     => 'required|integer|exists:users,id',
                'role'        => 'required|string|max:50',
                'permissions' => 'nullable|array',
                'permissions.*' => 'string',
            ], $this->getValidationMessages());

            // evita duplicação
            if (Employer::where('establishment_id', $establishment_id)
                    ->where('user_id', $data['user_id'])
=======
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
>>>>>>> staging
                    ->exists()
            ) {
                return response()->json([
                    'error' => 'Este usuário já é colaborador deste estabelecimento.'
                ], 409);
            }

<<<<<<< HEAD
            $employer = Employer::create([
                'user_id'          => $data['user_id'],
=======
            // cria vínculo
            $emp = Employer::create([
                'user_id'          => $targetUser->id,
>>>>>>> staging
                'establishment_id' => $establishment_id,
                'role'             => $data['role'],
                'permissions'      => $data['permissions'] ?? [],
                'created_by'       => $user->id,
                'updated_by'       => $user->id,
            ]);
<<<<<<< HEAD

            $employer->load('user');

            return response()->json([
                'message'       => 'Colaborador adicionado com sucesso.',
                'employer'      => $employer,
                'establishment' => $establishment,
=======
            $emp->load('user');

            // disparar emails
            Mail::to($targetUser->email)
                ->send(new NewEmployerCollaborator($est, $emp));
            Mail::to($user->email)
                ->send(new OwnerNotifiedNewCollaborator($est, $emp));

            return response()->json([
                'message'       => 'Colaborador adicionado com sucesso.',
                'employer'      => $emp,
                'establishment' => $est,
>>>>>>> staging
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
