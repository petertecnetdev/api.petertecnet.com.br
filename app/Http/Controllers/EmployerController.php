<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use App\Models\Establishment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EmployerController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    // Lista todos os colaboradores de um estabelecimento do proprietário autenticado
    public function list(Request $request, $establishment_id)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $establishment = Establishment::find($establishment_id);

            if (!$establishment || $establishment->user_id !== Auth::id()) {
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            $employers = Employer::with('user')
                ->where('establishment_id', $establishment_id)
                ->get();

            return response()->json([
                'message' => 'Colaboradores listados com sucesso.',
                'employers' => $employers,
                'establishment' => $establishment,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao listar colaboradores: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao listar colaboradores.'], 500);
        }
    }
}
