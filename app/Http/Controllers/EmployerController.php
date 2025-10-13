<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EmployerController extends Controller
{
    public function list(Request $request)
    {
        $request->validate([
            'establishment_id' => 'required|exists:establishments,id',
        ]);

        $employers = Employer::with('user')
            ->where('establishment_id', $request->input('establishment_id'))
            ->get();

        return response()->json($this->utf8ize($employers->toArray()));
    }

    public function store(Request $request)
    {
        Log::info('Employer.store start', ['user_id' => Auth::id(), 'payload' => $request->all()]);

        try {
            $validatedData = $request->validate([
                'establishment_id' => 'required|exists:establishments,id',
                'email'            => 'required|email',
                'role'             => 'required|string',
                'permissions'      => 'nullable|array',
            ]);

            $est = Establishment::find($validatedData['establishment_id']);
            if (!$est) {
                throw ValidationException::withMessages([
                    'establishment_id' => ['Estabelecimento não encontrado.']
                ]);
            }

            $user = User::firstOrCreate(
                ['email' => $validatedData['email']],
                ['password' => bcrypt(str()->random(8))]
            );

            $emp = Employer::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'establishment_id' => $est->id,
                ],
                [
                    'role' => $validatedData['role'],
                    'permissions' => $validatedData['permissions'] ?? [],
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]
            );

            $response = [
                'message' => 'Colaborador vinculado com sucesso.',
                'employer' => $emp->toArray(),
            ];

            return response()->json($this->utf8ize($response), 201);
        } catch (ValidationException $ve) {
            Log::error('ValidationException em Employer.store', [
                'errors'  => $ve->errors(),
                'payload' => $request->all(),
            ]);
            return response()->json(['errors' => $ve->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Exception em Employer.store', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error'   => 'Erro ao cadastrar colaborador.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $employer = Employer::findOrFail($id);
        $employer->delete();
        return response()->json(['message' => 'Colaborador removido com sucesso.']);
    }

    private function utf8ize($mixed)
    {
        if (is_array($mixed)) {
            foreach ($mixed as $key => $value) {
                $mixed[$key] = $this->utf8ize($value);
            }
        } elseif (is_string($mixed)) {
            return mb_convert_encoding($mixed, 'UTF-8', 'UTF-8');
        }
        return $mixed;
    }
}
