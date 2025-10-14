<?php

namespace App\Http\Controllers;

use App\Models\{Employer, Establishment, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Mail\CreatePasswordMail;
use App\Mail\{NewEmployerCollaborator, EmployerRemoved, OwnerNotifiedEmployerDetached, OwnerNotifiedNewCollaborator};

class EmployerController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'first_name.required' => 'O campo nome é obrigatório.',
            'first_name.string' => 'O campo nome deve ser um texto válido.',
            'first_name.max' => 'O campo nome não pode ter mais que 255 caracteres.',
            'email.required' => 'O campo e-mail é obrigatório.',
            'email.email' => 'O e-mail informado não é válido.',
            'email.max' => 'O e-mail não pode ter mais que 255 caracteres.',
            'establishment_id.required' => 'O ID do estabelecimento é obrigatório.',
            'establishment_id.integer' => 'O ID do estabelecimento deve ser um número inteiro.',
            'establishment_id.exists' => 'O estabelecimento informado não existe.',
            'link.required' => 'O campo link é obrigatório.',
            'link.url' => 'O link informado não é uma URL válida.',
            'role.required' => 'O campo função (role) é obrigatório.',
            'role.string' => 'O campo função deve ser um texto válido.',
            'permissions.required' => 'O campo permissões é obrigatório.',
            'permissions.array' => 'O campo permissões deve ser um array de permissões.',
        ];
    }

    public function store(Request $request)
    {
        try {
            Log::info('Employer.store start', ['user_id' => Auth::id(), 'payload' => $request->all()]);

            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();

            $validatedData = $request->validate([
                'first_name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'establishment_id' => 'required|integer|exists:establishments,id',
                'link' => 'required|url',
                'role' => 'required|string|max:255',
                'permissions' => 'required|array',
            ], $this->getValidationMessages());

            $establishment = Establishment::find($validatedData['establishment_id']);
            if (!$establishment) {
                return response()->json([
                    'error' => 'O estabelecimento informado não existe ou foi removido.'
                ], 404);
            }

            // ✅ Correção da verificação do dono do estabelecimento
            if ($establishment->user_id !== $user->id) {
                return response()->json([
                    'error' => 'Apenas o dono do estabelecimento pode adicionar novos colaboradores.'
                ], 403);
            }

            $existingUser = User::where('email', $validatedData['email'])->first();

            if (
                $existingUser && Employer::where('user_id', $existingUser->id)
                    ->where('establishment_id', $establishment->id)
                    ->exists()
            ) {
                return response()->json([
                    'error' => 'Este usuário já está vinculado a este estabelecimento.'
                ], 409);
            }

            if (!$existingUser) {
                $username = Str::slug($validatedData['first_name']) . '-' . Str::random(4);
                while (User::where('user_name', $username)->exists()) {
                    $username = Str::slug($validatedData['first_name']) . '-' . Str::random(4);
                }

                $password = Str::random(10);

                $newUser = User::create([
                    'first_name' => $validatedData['first_name'],
                    'name' => $validatedData['first_name'],
                    'email' => $validatedData['email'],
                    'user_name' => $username,
                    'password' => Hash::make($password),
                ]);

                $employer = Employer::create([
                    'user_id' => $newUser->id,
                    'establishment_id' => $establishment->id,
                    'role' => $validatedData['role'],
                    'permissions' => $validatedData['permissions'],
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                $createCode = Str::random(8);
                $newUser->reset_password_code = $createCode;
                $newUser->reset_password_expires_at = now()->addMinutes(10);
                $newUser->save();

                Mail::to($newUser->email)->send(new CreatePasswordMail($createCode, $newUser, $validatedData['link']));
                Mail::to($newUser->email)->send(new NewEmployerCollaborator($establishment, $employer));

                if ($establishment->user_id && $establishment->user) {
                    Mail::to($establishment->user->email)->send(new OwnerNotifiedNewCollaborator($establishment, $employer));
                }

                $message = 'Novo colaborador criado com sucesso. Um e-mail foi enviado para o colaborador finalizar o cadastro.';
            } else {
                $employer = Employer::create([
                    'user_id' => $existingUser->id,
                    'establishment_id' => $establishment->id,
                    'role' => $validatedData['role'],
                    'permissions' => $validatedData['permissions'],
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                Mail::to($existingUser->email)->send(new NewEmployerCollaborator($establishment, $employer));

                if ($establishment->user_id && $establishment->user) {
                    Mail::to($establishment->user->email)->send(new OwnerNotifiedNewCollaborator($establishment, $employer));
                }

                $message = 'Usuário já existente vinculado como colaborador com sucesso.';
            }

            Log::info('Employer.store success', ['employer_id' => $employer->id]);

            return response()->json([
                'message' => $message,
                'employer' => $employer,
            ], 201);

        } catch (ValidationException $e) {
            Log::warning('Employer.store validation failed', ['errors' => $e->errors()]);
            return response()->json([
                'message' => 'Erro de validação nos dados enviados.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Employer.store failed', ['error' => $e->getMessage(), 'stack' => $e->getTraceAsString()]);
            return response()->json([
                'error' => 'Ocorreu um erro inesperado ao adicionar o colaborador.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function listByEstablishment(Request $request)
    {
        try {
            Log::info('Employer.list start', [
                'user_id' => Auth::id(),
                'payload' => $request->all()
            ]);

            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $validatedData = $request->validate([
                'establishment_id' => 'required|integer|exists:establishments,id',
            ], [
                'establishment_id.required' => 'O ID do estabelecimento é obrigatório.',
                'establishment_id.integer' => 'O ID do estabelecimento deve ser um número inteiro válido.',
                'establishment_id.exists' => 'O estabelecimento informado não existe.',
            ]);

            $user = Auth::user();
            $establishment = Establishment::with('user')->find($validatedData['establishment_id']);

            if (!$establishment) {
                return response()->json([
                    'error' => 'O estabelecimento informado não existe ou foi removido.'
                ], 404);
            }

            if ($establishment->user_id !== $user->id) {
                return response()->json([
                    'error' => 'Apenas o dono do estabelecimento pode visualizar a lista de colaboradores.'
                ], 403);
            }

            $employers = Employer::with(['user:id,first_name,email,user_name', 'creator:id,first_name,email'])
                ->where('establishment_id', $establishment->id)
                ->orderByDesc('created_at')
                ->get();

            if ($employers->isEmpty()) {
                return response()->json([
                    'message' => 'Nenhum colaborador encontrado para este estabelecimento.'
                ], 200);
            }

            Log::info('Employer.list success', [
                'establishment_id' => $establishment->id,
                'count' => $employers->count()
            ]);

            return response()->json([
                'message' => 'Lista de colaboradores carregada com sucesso.',
                'establishment' => [
                    'id' => $establishment->id,
                    'name' => $establishment->name,
                ],
                'employers' => $employers
            ], 200);

        } catch (ValidationException $e) {
            Log::warning('Employer.list validation failed', ['errors' => $e->errors()]);
            return response()->json([
                'message' => 'Erro de validação nos dados enviados.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Employer.list failed', [
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Ocorreu um erro inesperado ao listar os colaboradores.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
    public function detach(Request $request)
{
    try {
        Log::info('Employer.detach start', [
            'user_id' => Auth::id(),
            'payload' => $request->all()
        ]);

        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        $validatedData = $request->validate([
            'employer_id' => 'required|integer|exists:employers,id',
            'establishment_id' => 'required|integer|exists:establishments,id',
        ], [
            'employer_id.required' => 'O ID do colaborador é obrigatório.',
            'employer_id.integer' => 'O ID do colaborador deve ser um número inteiro.',
            'employer_id.exists' => 'O colaborador informado não existe.',
            'establishment_id.required' => 'O ID do estabelecimento é obrigatório.',
            'establishment_id.integer' => 'O ID do estabelecimento deve ser um número inteiro.',
            'establishment_id.exists' => 'O estabelecimento informado não existe.',
        ]);

        $user = Auth::user();
        $establishment = Establishment::with('user')->find($validatedData['establishment_id']);

        if (!$establishment) {
            return response()->json([
                'error' => 'O estabelecimento informado não existe.'
            ], 404);
        }

        if ($establishment->user_id !== $user->id) {
            return response()->json([
                'error' => 'Apenas o dono do estabelecimento pode desvincular colaboradores.'
            ], 403);
        }

        $employer = Employer::with('user')->where('id', $validatedData['employer_id'])
            ->where('establishment_id', $establishment->id)
            ->first();

        if (!$employer) {
            return response()->json([
                'error' => 'O colaborador não está vinculado a este estabelecimento.'
            ], 404);
        }

        $collaboratorUser = $employer->user;
        $ownerUser = $establishment->user;

        $employer->delete();

        if ($collaboratorUser && !empty($collaboratorUser->email)) {
            try {
                Mail::to($collaboratorUser->email)
                    ->send(new \App\Mail\EmployerRemoved($establishment, $collaboratorUser));
            } catch (\Exception $e) {
                Log::warning('Failed to send EmployerRemoved email', [
                    'error' => $e->getMessage(),
                    'employer_id' => $validatedData['employer_id']
                ]);
            }
        }

        if ($ownerUser && !empty($ownerUser->email)) {
            try {
                Mail::to($ownerUser->email)
                    ->send(new \App\Mail\OwnerNotifiedEmployerDetached($establishment, $collaboratorUser ?? null));
            } catch (\Exception $e) {
                Log::warning('Failed to send OwnerNotifiedEmployerDetached email', [
                    'error' => $e->getMessage(),
                    'employer_id' => $validatedData['employer_id']
                ]);
            }
        }

        Log::info('Employer.detach success', [
            'employer_id' => $validatedData['employer_id'],
            'establishment_id' => $establishment->id
        ]);

        return response()->json([
            'message' => 'Colaborador desvinculado com sucesso e notificações enviadas.',
        ], 200);

    } catch (ValidationException $e) {
        Log::warning('Employer.detach validation failed', ['errors' => $e->errors()]);
        return response()->json([
            'message' => 'Erro de validação nos dados enviados.',
            'errors' => $e->errors()
        ], 422);

    } catch (\Exception $e) {
        Log::error('Employer.detach failed', [
            'error' => $e->getMessage(),
            'stack' => $e->getTraceAsString()
        ]);
        return response()->json([
            'error' => 'Ocorreu um erro inesperado ao desvincular o colaborador.',
            'details' => $e->getMessage(),
        ], 500);
    }
}


}
