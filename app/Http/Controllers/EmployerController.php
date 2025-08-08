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
            'establishment_id.integer'  => 'O ID do estabelecimento deve ser um número inteiro.',
            'establishment_id.exists'   => 'Estabelecimento não encontrado.',
            'email.required'            => 'O email do usuário é obrigatório.',
            'email.email'               => 'O email fornecido não é válido.',
            'role.required'             => 'O cargo (role) é obrigatório.',
            'role.string'               => 'O cargo deve ser uma string.',
            'role.max'                  => 'O cargo deve ter no máximo 50 caracteres.',
            'permissions.array'         => 'As permissões devem ser um array.',
            'permissions.*.string'      => 'Cada permissão deve ser uma string.',
        ];
    }

    /**
     * Lista todos os colaboradores de um estabelecimento,
     * recebendo establishment_id no body.
     */
    public function list(Request $request)
    {
        Log::info('Employer.list called', ['user_id' => Auth::id(), 'payload' => $request->all()]);
        try {
            if (!Auth::check()) {
                Log::warning('Tentativa de listagem sem autenticação.', ['payload' => $request->all()]);
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $data = $request->validate([
                'establishment_id' => 'required|integer|exists:establishments,id'
            ], $this->getValidationMessages());

            $user = Auth::user();
            $est = Establishment::find($data['establishment_id']);

            if ($est->user_id !== $user->id) {
                Log::warning('Acesso negado na listagem de colaboradores', ['establishment_id' => $est->id, 'user_id' => $user->id]);
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            $employers = Employer::with('user')
                ->where('establishment_id', $est->id)
                ->get();

            Log::info('Colaboradores listados com sucesso', ['establishment_id' => $est->id, 'count' => $employers->count()]);
            return response()->json([
                'message'       => 'Colaboradores listados com sucesso.',
                'employers'     => $employers,
                'establishment' => $est,
            ], 200);

        } catch (ValidationException $ve) {
            Log::error('ValidationException em Employer.list', ['errors' => $ve->errors(), 'payload' => $request->all()]);
            return response()->json(['errors' => $ve->errors()], 422);

        } catch (\Exception $e) {
            Log::error('Exception em Employer.list', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Erro ao listar colaboradores.'], 500);
        }
    }

    /**
     * Cadastra um colaborador em um estabelecimento via email.
     * Recebe establishment_id no body.
     */
    public function store(Request $request)
    {
        Log::info('Employer.store called', ['user_id' => Auth::id(), 'payload' => $request->all()]);
        try {
            if (!Auth::check()) {
                Log::warning('Tentativa de cadastro sem autenticação.', ['payload' => $request->all()]);
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $data = $request->validate([
                'establishment_id' => 'required|integer|exists:establishments,id',
                'email'            => 'required|email',
                'role'             => 'required|string|max:50',
                'permissions'      => 'nullable|array',
                'permissions.*'    => 'string',
            ], $this->getValidationMessages());

            $user = Auth::user();
            $est = Establishment::find($data['establishment_id']);
            if ($est->user_id !== $user->id) {
                Log::warning('Acesso negado no cadastro de colaborador', ['establishment_id' => $est->id, 'user_id' => $user->id]);
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            // busca usuário por email
            $targetUser = User::where('email', $data['email'])->first();
            if (!$targetUser) {
                Log::warning('Usuário não encontrado para adicionar', ['email' => $data['email']]);
                return response()->json(['error' => 'Usuário com este email não encontrado.'], 404);
            }

            // evita duplicação
            if (Employer::where('establishment_id', $est->id)
                    ->where('user_id', $targetUser->id)
                    ->exists()
            ) {
                Log::warning('Tentativa de duplicar colaborador', ['establishment_id' => $est->id, 'user_id' => $targetUser->id]);
                return response()->json([
                    'error' => 'Este usuário já é colaborador deste estabelecimento.'
                ], 409);
            }

            // cria vínculo
            $emp = Employer::create([
                'user_id'          => $targetUser->id,
                'establishment_id' => $est->id,
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
            Log::info('Emails enviados para novo colaborador e proprietário', ['collaborator_email' => $targetUser->email, 'owner_email' => $user->email]);

            return response()->json([
                'message'       => 'Colaborador adicionado com sucesso.',
                'employer'      => $emp,
                'establishment' => $est,
            ], 201);

        } catch (ValidationException $ve) {
            Log::error('ValidationException em Employer.store', ['errors' => $ve->errors(), 'payload' => $request->all()]);
            return response()->json(['errors' => $ve->errors()], 422);

        } catch (\Exception $e) {
            Log::error('Exception em Employer.store', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Erro ao cadastrar colaborador.'], 500);
        }
    }
}
