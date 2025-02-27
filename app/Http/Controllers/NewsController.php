<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\{News, Interaction};
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;


class NewsController extends Controller
{
    // Função para obter mensagens de validação personalizadas
    protected function getValidationMessages()
    {
        return [
            // Validações para o campo título
            'title.required' => 'O campo título é obrigatório.',
            'title.string' => 'O título deve ser uma string válida.',
            'title.max' => 'O título não pode ter mais que 255 caracteres.',
    
            // Validações para o campo conteúdo
            'content.required' => 'O conteúdo da notícia é obrigatório.',
            'content.string' => 'O conteúdo deve ser uma string válida.',
    
            // Validações para o campo imagem
            'image.required' => 'O campo imagem é obrigatório.',
            'image.image' => 'O arquivo deve ser uma imagem válida.',
            'image.mimes' => 'A imagem deve estar em um dos seguintes formatos: jpeg, png, jpg, gif.',
            'image.max' => 'A imagem não pode ser maior que 2MB.',
    
            // Validações para o campo de data de publicação (opcional, se necessário)
            'published_at.date' => 'A data de publicação deve ser uma data válida.',
    
            // Validações para o campo user_id (opcional, se necessário)
            'user_id.exists' => 'O usuário associado à notícia deve ser válido.',
    
            // Validações para o campo name
            'name.string' => 'O nome deve ser uma string válida.',
            'name.max' => 'O nome não pode ter mais que 255 caracteres.',
        ];
    }


    // Método responsável por listar todas as notícias
    public function list(Request $request)
    {
        try {
            // Definir o número de itens por página
            $perPage = 6; // Valor fixo de 6 itens por página
    
            // Obter o termo de pesquisa, se houver
            $search = $request->input('search', '');
    
            // Obter as notícias paginadas, com base na pesquisa
            $newsQuery = News::where('title', 'like', '%' . $search . '%')
                ->orWhere('content', 'like', '%' . $search . '%')
                ->orderBy('created_at', 'desc');
    
            $news = $newsQuery->paginate($perPage);
    
            return response()->json($news);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao buscar notícias.'], 500);
        }
    }
    

    public function store(Request $request)
    {
        try {
            // Log para iniciar o processo de validação
            Log::info('Iniciando validação dos campos para a criação da notícia.', $request->all());

            // Validação dos campos com mensagens personalizadas
            $request->validate([
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048', // Validação de imagem
            ], $this->getValidationMessages());

            Log::info('Validação dos campos concluída com sucesso.');

            // Processar e redimensionar a imagem
            $image = $request->file('image');
            $imagePath = 'images/news/' . uniqid() . '.' . $image->getClientOriginalExtension();

            // Redimensionar para 660x441 usando Intervention Image
            $resizedImage = Image::make($image)->resize(660, 441);
            $resizedImage->save(storage_path('app/public/' . $imagePath));

            Log::info('Imagem redimensionada e salva com sucesso em: ' . $imagePath);

            // Recuperar o ID do usuário autenticado, se houver
            $userId = auth()->check() ? auth()->id() : null;

            // Criar nova notícia
            $news = News::create([
                'title' => $request->input('title'),
                'content' => $request->input('content'),
                'slug' => Str::slug($request->input('title')), // Slug gerado automaticamente
                'image' => $imagePath, // Caminho da imagem armazenada
                'user_id' => $userId, // Se o usuário estiver autenticado, salvar o ID dele
            ]);

            Log::info('Notícia criada com sucesso: ', $news->toArray());

            return response()->json(['message' => 'Notícia criada com sucesso.', 'news' => $news], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Log para erro de validação
            Log::error('Erro de validação ao criar notícia: ' . $e->getMessage());
            Log::error('Detalhes dos erros de validação: ', $e->errors());

            // Retornar os erros de validação para o front-end
            return response()->json([
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            // Log para qualquer outro erro
            Log::error('Erro ao criar notícia: ' . $e->getMessage());

            return response()->json(['error' => 'Ocorreu um erro ao cadastrar a notícia'], 500);
        }
    }


    public function update(Request $request, $id)
    {
        try {
            // Encontrar a notícia
            $news = News::findOrFail($id);
    
            // Validação dos campos
            $validatedData = $request->validate([
                'title' => 'nullable|string|max:255',
                'content' => 'nullable|string',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Validação de imagem
            ], $this->getValidationMessages());
    
            // Atualizar os campos, se necessário
            $news->fill($request->only(['title', 'content', 'published_at']));
    
            // Verifica se uma nova imagem foi enviada
            if ($request->hasFile('image')) {
                // Armazenar nova imagem
                $imagePath = $request->file('image')->store('images/news', 'public');
                $news->image = $imagePath;
            }
    
            // Forçar atualização no campo updated_at
            $news->updated_at = now();
    
            // Salvar a notícia
            $news->save();
    
            // Retornar resposta
            return response()->json(['message' => 'Notícia atualizada com sucesso.', 'news' => $news], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Logar erros de validação
            Log::error('Erro de validação ao atualizar a notícia: ', [
                'errors' => $e->validator->errors(),
                'request_data' => $request->all(),
                'news_id' => $id,
            ]);
            return response()->json(['errors' => $e->validator->errors()], 422);
        } catch (\Exception $e) {
            // Logar erros gerais
            Log::error('Erro ao atualizar a notícia: ', [
                'message' => $e->getMessage(),
                'request_data' => $request->all(),
                'news_id' => $id,
            ]);
            return response()->json(['error' => 'Ocorreu um erro ao atualizar a notícia'], 500);
        }
    }
    
    // Método responsável por exibir uma notícia específica
    public function show($id)
    {
        try {
            // Verificar se a notícia existe
            $news = News::with('comments')->findOrFail($id);
    
            // Sortear 3 notícias aleatórias diferentes da notícia atual
            $recommendedNews = News::where('id', '!=', $id)
                ->inRandomOrder()
                ->take(3) // Define quantas notícias aleatórias você quer
                ->get();
    
            return response()->json(['news' => $news, 'recommended_news' => $recommendedNews], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Notícia não encontrada com ID: ' . $id);
            return response()->json(['error' => 'Notícia não encontrada.'], 404);
        } catch (\Exception $e) {
            Log::error('Erro ao mostrar a notícia: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao recuperar a notícia.'], 500);
        }
    }
    


    // Método responsável por deletar uma notícia
    public function destroy($id)
    {
        try {
            // Verificar se a notícia existe
            $news = News::findOrFail($id);

            // Deletar a notícia
            $news->delete();

            return response()->json(['message' => 'Notícia deletada com sucesso.'], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Notícia não encontrada para deletar com ID: ' . $id);
            return response()->json(['error' => 'Notícia não encontrada.'], 404);
        } catch (\Exception $e) {
            Log::error('Erro ao deletar a notícia: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao deletar a notícia.'], 500);
        }
    }
    public function search(Request $request)
    {
        try {
            // Obter o termo de pesquisa
            $search = $request->input('search', '');

            // Definir o número de itens por página
            $perPage = 6; // Valor fixo de 6 itens por página

            // Log do termo de pesquisa
            Log::info('Iniciando busca de notícias com o termo: ' . $search);

            // Obter as notícias paginadas com base na pesquisa
            $news = News::where('title', 'like', '%' . $search . '%')
                ->orWhere('content', 'like', '%' . $search . '%')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            // Retorna o objeto de paginação das notícias
            return response()->json($news, 200);
        } catch (\Exception $e) {
            // Log para qualquer erro que ocorra
            Log::error('Erro ao buscar notícias: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao buscar as notícias.'], 500);
        }
    }

    public function comment(Request $request, $newsId)
    {
        try {
            // Log para iniciar o processo de validação
            Log::info('Iniciando validação do comentário.', $request->all());

            // Validação dos campos do comentário
            $request->validate([
                'comment' => 'required|string|max:1000', // Você pode ajustar o limite de caracteres
                'name' => 'nullable|string|max:255', // Nome do usuário não autenticado (opcional)
            ], [
                'comment.required' => 'O comentário é obrigatório.',
                'comment.string' => 'O comentário deve ser uma string.',
                'comment.max' => 'O comentário não pode ter mais que 1000 caracteres.',
                'name.string' => 'O nome deve ser uma string.',
                'name.max' => 'O nome não pode ter mais que 255 caracteres.',
            ]);

            // Recuperar o ID do usuário autenticado
            $userId = auth()->check() ? auth()->id() : null;

            // Recuperar o nome do usuário se não estiver autenticado
            $userName = auth()->check() ? null : $request->input('name');

            // Verificar se o usuário não está autenticado e forneceu o nome
            if (!$userId && !$userName) {
                return response()->json(['error' => 'É necessário fornecer o nome se não estiver autenticado.'], 422);
            }

            // Criação do registro de interação
            $interaction = Interaction::create([
                'user_id' => $userId, // ID do usuário logado (null se não autenticado)
                'name' => $userName, // Nome do usuário (pode ser null se autenticado)
                'entity_id' => $newsId, // ID da notícia que está sendo comentada
                'entity_type' => 'news', // Entidade que está sendo comentada
                'interaction_type' => 'comment', // Tipo de interação
                'comment' => $request->input('comment'), // Conteúdo do comentário
            ]);

            Log::info('Comentário adicionado com sucesso: ', $interaction->toArray());

            return response()->json(['message' => 'Comentário adicionado com sucesso.', 'interaction' => $interaction], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Log para erro de validação
            Log::error('Erro de validação ao adicionar comentário: ' . $e->getMessage());
            Log::error('Detalhes dos erros de validação: ', $e->errors());

            // Retornar os erros de validação para o front-end
            return response()->json([
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            // Log para qualquer outro erro
            Log::error('Erro ao adicionar comentário: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao adicionar o comentário.'], 500);
        }
    }




}
