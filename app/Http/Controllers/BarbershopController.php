<?php

namespace App\Http\Controllers;

use App\Models\Barbershop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BarbershopController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'name.required' => 'O nome da barbearia é obrigatório.',
            'name.unique' => 'Já existe uma barbearia cadastrada com esse nome.',
            'email.required' => 'O email da barbearia é obrigatório.',
            'email.email' => 'O email fornecido não é válido.',
            'email.unique' => 'Já existe uma barbearia cadastrada com esse email.',
            'phone.max' => 'O telefone deve ter no máximo 20 caracteres.',
            'address.required' => 'O endereço é obrigatório.',
            'city.required' => 'A cidade é obrigatória.',
            'state.required' => 'O estado é obrigatório.',
            'zipcode.required' => 'O CEP é obrigatório.',
            'website.url' => 'O website deve ser um URL válido.',
            'latitude.numeric' => 'A latitude deve ser um número.',
            'longitude.numeric' => 'A longitude deve ser um número.',
            'status.integer' => 'O status deve ser um número inteiro.',
            'logo.image' => 'A logo deve ser uma imagem válida (jpeg, png, bmp, gif, svg, ou webp).',
            'logo.max' => 'O nome do arquivo da logo deve ter no máximo 255 caracteres.',
            'background_image.image' => 'A imagem de fundo deve ser uma imagem válida (jpeg, png, bmp, gif, svg, ou webp).',
            'background_image.max' => 'O nome do arquivo da imagem de fundo deve ter no máximo 255 caracteres.',
            'social_media_links.json' => 'Os links de redes sociais devem estar em formato JSON.',
        ];
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

     // Verificar se o usuário possui permissão para listar barbearias
     if (!$user->hasPermission('barbershop_store')) {
         return response()->json(['error' => 'Você não tem permissão para cadastrar barbearias.'], 403);
     }
    
            // Validação dos dados da requisição
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:barbershops,name',
                'email' => 'required|email|max:255|unique:barbershops,email',
                'phone' => 'nullable|string|max:20',
                'address' => 'required|string|max:255',
                'city' => 'required|string|max:100',
                'state' => 'required|string|max:100',
                'zipcode' => 'required|string|max:10',
                'website' => 'nullable|url',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'status' => 'required|integer',
                'logo' => 'nullable|image|mimes:jpeg,png,bmp,gif,svg|max:2048',
                'background_image' => 'nullable|image|mimes:jpeg,png,bmp,gif,svg|max:2048',
                'social_media_links' => 'nullable|json',
            ], $this->getValidationMessages());
    
            // Console log para mostrar os dados recebidos
            Log::info('Dados recebidos para criação da barbearia:', $validatedData);
    
            // Criação da barbearia
            $barbershop = Barbershop::create([
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'phone' => $validatedData['phone'],
                'address' => $validatedData['address'],
                'city' => $validatedData['city'],
                'state' => $validatedData['state'],
                'zipcode' => $validatedData['zipcode'],
                'website' => $validatedData['website'],
                'latitude' => $validatedData['latitude'],
                'longitude' => $validatedData['longitude'],
                'status' => (int) $validatedData['status'],
                'social_media_links' => $validatedData['social_media_links'],
                'user_id' => $user->id, // Aqui você associa o user_id
            ]);
    
            // Processar e salvar a logo, se fornecida
            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store('public/barbershops');
                $barbershop->logo = str_replace('public/', '', $logoPath);
                $barbershop->save();
            }
    
            // Processar e salvar a imagem de fundo, se fornecida
            if ($request->hasFile('background_image')) {
                $backgroundImagePath = $request->file('background_image')->store('public/barbershops/backgrounds');
                $barbershop->background_image = str_replace('public/', '', $backgroundImagePath);
                $barbershop->save();
            }
    
            // Retornar sucesso
            return response()->json(['message' => 'Barbearia cadastrada com sucesso.', 'barbershop' => $barbershop], 201);
    
        } catch (ValidationException $e) {
            // Captura erros de validação e retorna como resposta JSON
            return response()->json([
                'errors' => $e->errors(),
            ], 422);
    
        } catch (\Exception $e) {
            // Log do erro e retorno de mensagem genérica
            Log::error('Erro ao cadastrar barbearia: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao cadastrar a barbearia.'], 500);
        }
    }
    

    public function list(Request $request)
    {
        try {
            // Verificar se o usuário está autenticado
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Obter o usuário autenticado
            $user = Auth::user();

            // Verificar se o usuário possui permissão para listar barbearias
            if (!$user->hasPermission('barbershop_list')) {
                return response()->json(['error' => 'Você não tem permissão para listar barbearias.'], 403);
            }

            // Obter todas as barbearias com paginação
            $barbershops = Barbershop::paginate(10); // Defina a quantidade de barbearias por página

            // Retornar a lista de barbearias
            return response()->json([
                'message' => 'Barbearias listadas com sucesso.',
                'barbershops' => $barbershops,
            ], 200);

        } catch (\Exception $e) {
            // Log do erro e retorno de mensagem genérica
            Log::error('Erro ao listar barbearias: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar as barbearias.'], 500);
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

       // Verificar se o usuário possui permissão para listar barbearias
       if (!$user->hasPermission('barbershop_update')) {
           return response()->json(['error' => 'Você não tem permissão para atualizar barbearias.'], 403);
       }

            // Obter o usuário autenticado
            $user = Auth::user();
    
            // Verificar se a barbearia existe
            $barbershop = Barbershop::findOrFail($id);
    
            // Validação dos dados da requisição
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:barbershops,name,' . $barbershop->id,
                'email' => 'required|email|max:255|unique:barbershops,email,' . $barbershop->id,
                'phone' => 'nullable|string|max:20',
                'address' => 'required|string|max:255',
                'city' => 'required|string|max:100',
                'state' => 'required|string|max:100',
                'zipcode' => 'required|string|max:10',
                'website' => 'nullable|url',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'status' => 'required|integer',
                'logo' => 'nullable|image|mimes:jpeg,png,bmp,gif,svg|max:2048',
                'background_image' => 'nullable|image|mimes:jpeg,png,bmp,gif,svg|max:2048',
                'social_media_links' => 'nullable|json',
            ], $this->getValidationMessages());
    
            // Console log para mostrar os dados recebidos
            Log::info('Dados recebidos para atualização da barbearia:', $validatedData);
    
            // Atualização da barbearia
            $barbershop->update(array_merge($validatedData, [
                'updated_by' => $user->id, // Atualiza o usuário que fez a alteração
            ]));
    
            // Processar e salvar a logo, se fornecida
            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store('public/barbershops');
                $barbershop->logo = str_replace('public/', '', $logoPath);
            }
    
            // Processar e salvar a imagem de fundo, se fornecida
            if ($request->hasFile('background_image')) {
                $backgroundImagePath = $request->file('background_image')->store('public/barbershops/backgrounds');
                $barbershop->background_image = str_replace('public/', '', $backgroundImagePath);
            }
    
            // Salvar as alterações
            $barbershop->save();
    
            // Retornar sucesso
            return response()->json(['message' => 'Barbearia atualizada com sucesso.', 'barbershop' => $barbershop], 200);
    
        } catch (ValidationException $e) {
            // Captura erros de validação e retorna como resposta JSON
            return response()->json([
                'errors' => $e->errors(),
            ], 422);
    
        } catch (\Exception $e) {
            // Log do erro e retorno de mensagem genérica
            Log::error('Erro ao atualizar barbearia: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao atualizar a barbearia.'], 500);
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

        // Verificar se o usuário possui permissão para listar barbearias
        if (!$user->hasPermission('barbershop_destroy')) {
            return response()->json(['error' => 'Você não tem permissão para deletar barbearias.'], 403);
        }
            // Verificar se a barbearia existe
            $barbershop = Barbershop::findOrFail($id);
    
            // Excluir a barbearia
            $barbershop->delete();
    
            // Retornar sucesso
            return response()->json(['message' => 'Barbearia excluída com sucesso.'], 200);
    
        } catch (\ModelNotFoundException $e) {
            // Retornar erro se a barbearia não for encontrada
            return response()->json(['error' => 'Barbearia não encontrada.'], 404);
    
        } catch (\Exception $e) {
            // Log do erro e retorno de mensagem genérica
            Log::error('Erro ao excluir barbearia: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao excluir a barbearia.'], 500);
        }
    }
    public function listByUser(Request $request)
    {
        try {
            // Verificar se o usuário está autenticado
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Obter o usuário autenticado
            $user = Auth::user();

            // Verificar se o usuário possui permissão para listar suas barbearias
            if (!$user->hasPermission('barbershop_list')) {
                return response()->json(['error' => 'Você não tem permissão para listar suas barbearias.'], 403);
            }

            // Obter as barbearias do usuário autenticado
            $barbershops = Barbershop::where('user_id', $user->id)->paginate(10); // Defina a quantidade de barbearias por página

            // Retornar a lista de barbearias do usuário
            return response()->json([
                'message' => 'Barbearias listadas com sucesso.',
                'barbershops' => $barbershops,
            ], 200);

        } catch (\Exception $e) {
            // Log do erro e retorno de mensagem genérica
            Log::error('Erro ao listar barbearias do usuário: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar suas barbearias.'], 500);
        }
    }
}
