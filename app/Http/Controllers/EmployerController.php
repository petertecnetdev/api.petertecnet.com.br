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
            'establishment_id.required' => 'O ID do estabelecimento é obrigatório.',
            'establishment_id.integer' => 'O ID do estabelecimento deve ser um número inteiro.',
            'establishment_id.exists' => 'Estabelecimento não encontrado.',
            'email.required' => 'O email do usuário é obrigatório.',
            'email.email' => 'O email fornecido não é válido.',
            'role.required' => 'O cargo (role) é obrigatório.',
            'role.string' => 'O cargo deve ser uma string.',
            'role.max' => 'O cargo deve ter no máximo 50 caracteres.',
            'permissions.array' => 'As permissões devem ser um array.',
            'permissions.*.string' => 'Cada permissão deve ser uma string.',
        ];
    }

    /**
     * Lista todos os colaboradores de um estabelecimento (establishment_id no body).
     */

    public function list($establishment_id)
{
    if (!is_numeric($establishment_id)) {
        return response()->json([
            'errors' => ['establishment_id' => ['O ID do estabelecimento deve ser numérico.']]
        ], 422);
    }

    $est = Establishment::find($establishment_id);
    if (!$est) {
        return response()->json([
            'errors' => ['establishment_id' => ['Estabelecimento não encontrado.']]
        ], 404);
    }

    $employers = Employer::with('user')
        ->where('establishment_id', $est->id)
        ->get()
        ->map(function($emp) {
            return [
                'id' => $emp->id,
                'user_id' => $emp->user_id,
                'user_name' => isset($emp->user->first_name) ? mb_convert_encoding($emp->user->first_name, 'UTF-8', 'UTF-8') : null,
                'user_email' => isset($emp->user->email) ? mb_convert_encoding($emp->user->email, 'UTF-8', 'UTF-8') : null,
                'role' => mb_convert_encoding($emp->role, 'UTF-8', 'UTF-8'),
                'permissions' => $emp->permissions ?? [],
                'created_at' => $emp->created_at ? $emp->created_at->toDateTimeString() : null,
                'updated_at' => $emp->updated_at ? $emp->updated_at->toDateTimeString() : null,
            ];
        });

    return response()->json([
        'establishment' => [
            'id' => $est->id,
            'name' => mb_convert_encoding($est->name, 'UTF-8', 'UTF-8'),
        ],
        'employers' => $employers,
    ]);
}

    /**
     * Cadastra um colaborador em um estabelecimento via email.
     * Recebe establishment_id no body.
     */


    public function store(Request $request)
    {
        Log::info('Employer.store start', [
            'user_id' => Auth::id(),
            'payload' => $request->all(),
        ]);

        try {
            if (!Auth::check()) {
                Log::warning('Cadastro não autenticado', ['payload' => $request->all()]);
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $data = $request->validate([
                'establishment_id' => 'required|integer|exists:establishments,id',
                'email' => 'required|email',
                'role' => 'required|string|max:50',
                'permissions' => 'nullable|array',
                'permissions.*' => 'string',
            ], $this->getValidationMessages());

            Log::info('Store: dados válidos', ['data' => $data]);

            $user = Auth::user();
            $est = Establishment::find($data['establishment_id']);
            Log::debug('Estabelecimento para cadastro', [
                'establishment_id' => $est->id,
                'owner_id' => $est->user_id,
            ]);

            if ($est->user_id !== $user->id) {
                Log::warning('Cadastro negado - proprietário diferente', [
                    'est_user_id' => $est->user_id,
                    'auth_user_id' => $user->id,
                ]);
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            $targetUser = User::where('email', $data['email'])->first();
            if (!$targetUser) {
                Log::warning('Usuário não encontrado para cadastro', [
                    'email_informado' => $data['email'],
                ]);
                return response()->json([
                    'error' => 'Não foi possível adicionar o colaborador.',
                    'details' => 'Não existe usuário cadastrado com este email. Peça que o colaborador se registre ou verifique o email correto.'
                ], 404);
            }

            Log::info('Usuário alvo encontrado', [
                'target_user_id' => $targetUser->id,
                'target_email' => $targetUser->email,
            ]);

            if (
                Employer::where('establishment_id', $est->id)
                    ->where('user_id', $targetUser->id)
                    ->exists()
            ) {
                Log::warning('Tentativa de duplicar colaborador', [
                    'establishment_id' => $est->id,
                    'user_id' => $targetUser->id,
                ]);
                return response()->json(['error' => 'Colaborador já existente.'], 409);
            }

            $emp = Employer::create([
                'user_id' => $targetUser->id,
                'establishment_id' => $est->id,
                'role' => $data['role'],
                'permissions' => $data['permissions'] ?? [],
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $emp->load('user');

            Log::info('Vínculo criado', [
                'employer_id' => $emp->id,
                'establishment_id' => $est->id,
            ]);

            // Envia e-mails em blocos try/catch
            try {
                Mail::to($emp->user->email)
                    ->send(new NewEmployerCollaborator($est, $emp));
                Log::info('Email NewEmployerCollaborator enviado', [
                    'to' => $emp->user->email,
                    'subject' => "Você foi adicionado(a) em {$est->name}",
                ]);
            } catch (\Exception $mailEx) {
                Log::error('Falha ao enviar NewEmployerCollaborator', [
                    'to' => $emp->user->email,
                    'error' => $mailEx->getMessage(),
                ]);
            }

            try {
                Mail::to($user->email)
                    ->send(new OwnerNotifiedNewCollaborator($est, $emp));
                Log::info('Email OwnerNotifiedNewCollaborator enviado', [
                    'to' => $user->email,
                    'subject' => "Novo colaborador adicionado em {$est->name}",
                ]);
            } catch (\Exception $mailEx) {
                Log::error('Falha ao enviar OwnerNotifiedNewCollaborator', [
                    'to' => $user->email,
                    'error' => $mailEx->getMessage(),
                ]);
            }

            // Para evitar "Malformed UTF-8", retorne só campos limpos:
            $response = [
                'message' => 'Colaborador adicionado com sucesso.',
                'employer' => [
                    'id' => $emp->id,
                    'user_id' => $emp->user_id,
                    'user_name' => mb_convert_encoding($emp->user->first_name, 'UTF-8', 'UTF-8'),
                    'role' => mb_convert_encoding($emp->role, 'UTF-8', 'UTF-8'),
                    'permissions' => $emp->permissions,
                ],
                'establishment' => [
                    'id' => $est->id,
                    'name' => mb_convert_encoding($est->name, 'UTF-8', 'UTF-8'),
                ],
            ];

            Log::info('Employer.store end — sucesso', [
                'employer_id' => $emp->id,
                'establishment_id' => $est->id,
            ]);

            return response()->json($response, 201);

        } catch (ValidationException $ve) {
            Log::error('ValidationException em Employer.store', [
                'errors' => $ve->errors(),
                'payload' => $request->all(),
            ]);
            return response()->json(['errors' => $ve->errors()], 422);

        } catch (\Exception $e) {
            Log::error('Employer.store end — falha', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Erro ao cadastrar colaborador.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }


}
