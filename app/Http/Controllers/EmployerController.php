<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use App\Models\Establishment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Mail\EmployerAddedMail;
use Illuminate\Support\Facades\Mail;

class EmployerController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    protected function getValidationMessages()
    {
        return [
            'user_id.required' => 'O ID do usuário é obrigatório.',
            'user_id.exists' => 'O usuário informado não existe.',
            'establishment_id.required' => 'O ID do estabelecimento é obrigatório.',
            'establishment_id.exists' => 'O estabelecimento informado não existe.',
            'role.string' => 'O campo de função deve ser uma string.',
            'permissions.array' => 'As permissões devem estar em formato de array.',
        ];
    }


    public function store(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'establishment_id' => 'required|exists:establishments,id',
                'role' => 'nullable|string|max:100',
                'permissions' => 'nullable|array',
            ], $this->getValidationMessages());

            $establishment = \App\Models\Establishment::find($validated['establishment_id']);

            if (!$establishment || $establishment->user_id !== Auth::id()) {
                return response()->json([
                    'error' => 'Apenas o proprietário do estabelecimento pode associar funcionários.'
                ], 403);
            }

            $exists = Employer::where('user_id', $validated['user_id'])
                ->where('establishment_id', $validated['establishment_id'])
                ->exists();
            if ($exists) {
                return response()->json([
                    'error' => 'Este funcionário já está associado a este estabelecimento.'
                ], 422);
            }

            $employer = new Employer();
            $employer->user_id = $validated['user_id'];
            $employer->establishment_id = $validated['establishment_id'];
            $employer->role = $validated['role'] ?? null;
            $employer->permissions = $validated['permissions'] ?? [];
            $employer->created_by = Auth::id();
            $employer->save();

            $employer->load(['user', 'establishment']);

            // Envia email para o novo colaborador
            if ($employer->user && $employer->user->email) {
                Mail::to($employer->user->email)
                    ->send(new EmployerAddedMail($employer->establishment, $employer->user, $employer->role));
            }
            return response()->json([
                'message' => 'Funcionário associado com sucesso ao estabelecimento.',
                'employer' => $employer,
            ], 201);

        } catch (ValidationException $e) {
            Log::error('Erro de validação ao associar funcionário.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            Log::error('Erro ao associar funcionário: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao associar o funcionário.'], 500);
        }
    }

}
