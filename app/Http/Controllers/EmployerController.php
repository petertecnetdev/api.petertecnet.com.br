<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\{Employer, User, Establishment};
use Illuminate\Validation\ValidationException;
use App\Mail\NewEmployerCollaborator;
use App\Mail\InviteNewUserToEmployer;
use Illuminate\Support\Facades\Mail;

class EmployerController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'email.required' => 'O campo email é obrigatório.',
            'email.email' => 'O campo email deve ser um email válido.',
            'role.required' => 'O campo cargo é obrigatório.',
            'establishment_id.required' => 'O ID do estabelecimento é obrigatório.',
            'establishment_id.integer' => 'O ID do estabelecimento deve ser um número inteiro.',
        ];
    }

    private function utf8ize($data)
    {
        if (is_array($data)) {
            foreach ($data as $k => $v) $data[$k] = $this->utf8ize($v);
        } elseif (is_string($data)) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }
        return $data;
    }

    public function store(Request $request)
    {
        try {
            Log::info('Iniciando a criação de um novo colaborador.', $this->utf8ize($request->all()));

            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();

            if (!$user->hasPermission('employer_create')) {
                return response()->json(['error' => 'Você não tem permissão para cadastrar colaboradores.'], 403);
            }

            $validatedData = $request->validate([
                'email' => 'required|email|max:255',
                'role' => 'required|string|max:100',
                'permissions' => 'nullable|array',
                'establishment_id' => 'required|integer|exists:establishments,id',
            ], $this->getValidationMessages());

            $establishment = Establishment::find($validatedData['establishment_id']);
            if (!$establishment) {
                return response()->json(['error' => 'Estabelecimento não encontrado.'], 404);
            }

            $existingUser = User::where('email', $validatedData['email'])->first();

            if ($existingUser) {
                $employer = Employer::create([
                    'user_id' => $existingUser->id,
                    'establishment_id' => $establishment->id,
                    'role' => $validatedData['role'],
                    'permissions' => $validatedData['permissions'] ?? [],
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                Mail::to($existingUser->email)->send(new NewEmployerCollaborator($employer));

                Log::info('Colaborador existente vinculado ao estabelecimento.', $this->utf8ize([
                    'user_id' => $existingUser->id,
                    'establishment_id' => $establishment->id,
                    'employer_id' => $employer->id
                ]));

                return response()->json([
                    'message' => 'Colaborador vinculado com sucesso.',
                    'employer' => $employer->toArray()
                ], 201);

            } else {
                $verificationCode = Str::random(40);
                $newUser = User::create([
                    'email' => $validatedData['email'],
                    'verification_code' => $verificationCode,
                    'password' => '',
                ]);

                $employer = Employer::create([
                    'user_id' => $newUser->id,
                    'establishment_id' => $establishment->id,
                    'role' => $validatedData['role'],
                    'permissions' => $validatedData['permissions'] ?? [],
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                Mail::to($newUser->email)->send(new InviteNewUserToEmployer($employer, $verificationCode));

                Log::info('Novo colaborador criado e email enviado para completar cadastro.', $this->utf8ize([
                    'email' => $newUser->email,
                    'establishment_id' => $establishment->id,
                    'employer_id' => $employer->id
                ]));

                return response()->json([
                    'message' => 'Convite enviado para completar o cadastro do colaborador.',
                    'employer' => $employer->toArray()
                ], 201);
            }

        } catch (ValidationException $ve) {
            Log::error('ValidationException em Employer.store', [
                'errors'  => $this->utf8ize($ve->errors()),
                'payload' => $this->utf8ize($request->all()),
            ]);
            return response()->json(['errors' => $ve->errors()], 422);

        } catch (\Exception $e) {
            Log::error('Erro ao cadastrar colaborador: ' . $e->getMessage(), [
                'stack' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Erro ao cadastrar colaborador.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
