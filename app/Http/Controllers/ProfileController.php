<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\{Profile};
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    
    public function list()
    {
        try {
            // Verificar se o usuário está autenticado
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }
    
            // Obter o usuário autenticado
            $user = Auth::user();
    
            // Verificar se o usuário possui permissão para listar perfis
            if (!$user->hasPermission('profile_list')) {
                return response()->json(['error' => 'Você não tem permissão para listar perfis.'], 403);
            }
    
            // Obter todos os perfis
            $profiles = Profile::all();
    
            return response()->json(['profiles' => $profiles], 200);
        } catch (\Exception $e) {
            // Console log para mostrar o erro
            \Log::error('Erro ao listar perfis:', ['exception' => $e]);
    
            return response()->json(['error' => 'Erro ao listar perfis. Por favor, tente novamente.'], 500);
        }
    }
    
    public function store(Request $request)
{
    try {
        // Verificar se o usuário está autenticado
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        // Obter o usuário autenticado
        $user = Auth::user();


        $data = $request->validate([
            'name' => 'required|string',
            'permissions' => 'nullable|array',
        ]);

        // Console log para mostrar os dados recebidos
        \Log::info('Dados recebidos para criação de perfil:', $data);

        $profile = Profile::create($data);

        return response()->json(['message' => 'Perfil criado com sucesso.', 'profile' => $profile], 201);
    } catch (\Exception $e) {
        // Console log para mostrar o erro
        \Log::error('Erro ao criar perfil:', ['exception' => $e]);

        return response()->json(['error' => 'Erro ao criar perfil. Por favor, tente novamente.'], 500);
    }
}

public function update(Request $request, $id)
{
    try {
        // Verificar se o usuário está autenticado
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        // Obter o usuário autenticado
        $user = Auth::user();

        // Verificar se o usuário possui permissão para editar perfis
        if (!$user->hasPermission('profile_edit')) {
            return response()->json(['error' => 'Você não tem permissão para editar perfis.'], 403);
        }
        $profile = Profile::find($id);
        if (!$profile) {
            return response()->json(['error' => 'Perfil não encontrado.'], 404);
        }
        // Validar os dados da requisição
        $request->validate([
            'name' => 'required|string',
            'permissions' => 'nullable|array',
        ]);

        // Inicializar um array para armazenar os dados atualizados
        $data = [];

        // Verificar e atualizar o nome do perfil, se fornecido na solicitação
        if ($request->has('name')) {
            $data['name'] = $request->input('name');
        }

        // Verificar e atualizar as permissões do perfil, se fornecidas na solicitação
        if ($request->has('permissions')) {
            $data['permissions'] = $request->input('permissions');
        }

        // Verificar se houve alguma atualização nos dados
        if (!empty($data)) {
            // Atualizar os dados do perfil
            $profile->update($data);
        }
        \Log::info('Dados recebidos para atualização:', $data);
        // Retornar uma resposta de sucesso
        return response()->json(['message' => 'Perfil atualizado com sucesso.', 'profile' => $profile], 200);
    } catch (\Exception $e) {
        // Registrar o erro no log
        \Log::error('Erro ao atualizar perfil:', ['exception' => $e]);

        // Retornar uma resposta de erro
        return response()->json(['error' => 'Erro ao atualizar perfil. Por favor, tente novamente.'], 500);
    }
}

public function show($id)
{
    try {
        // Verificar se o usuário está autenticado
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        // Verificar se o perfil existe
        $profile = Profile::find($id);
        if (!$profile) {
            return response()->json(['error' => 'Perfil não encontrado.'], 404);
        }

        // Obter o usuário autenticado
        $user = Auth::user();

        // Verificar se o usuário possui permissão para visualizar o perfil
        if (!$user->hasPermission('profile_view')) {
            return response()->json(['error' => 'Você não tem permissão para visualizar perfis.'], 403);
        }

        return response()->json(['profile' => $profile], 200);
    } catch (\Exception $e) {
        // Console log para mostrar o erro
        \Log::error('Erro ao exibir perfil:', ['exception' => $e]);

        return response()->json(['error' => 'Erro ao exibir perfil. Por favor, tente novamente.'], 500);
    }
}


public function destroy($id)
{
    try {
        // Verificar se o usuário está autenticado
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        // Obter o usuário autenticado
        $user = Auth::user();

        // Verificar se o usuário possui permissão para excluir perfis
        if (!$user->hasPermission('profile_delete')) {
            return response()->json(['error' => 'Você não tem permissão para excluir perfis.'], 403);
        }

        // Verificar se o perfil existe
        $profile = Profile::find($id);
        if (!$profile) {
            return response()->json(['error' => 'Perfil não encontrado.'], 404);
        }

        // Excluir o perfil
        $profile->delete();

        return response()->json(['success' => 'Perfil excluído com sucesso.'], 200);
    } catch (\Exception $e) {
        // Console log para mostrar o erro
        \Log::error('Erro ao excluir perfil:', ['exception' => $e]);

        return response()->json(['error' => 'Erro ao excluir perfil. Por favor, tente novamente.'], 500);
    }
}

}
