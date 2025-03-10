<?php

namespace App\Http\Controllers;

use App\Models\{Barbershop, Interaction, User, Barber};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Str;


class BarbershopController extends Controller
{

    protected function getValidationMessages()
    {
        return [
            'name.required' => 'O nome da barbearia é obrigatório.',
            'name.string' => 'O nome da barbearia deve ser uma string válida.',
            'name.max' => 'O nome da barbearia deve ter no máximo 255 caracteres.',
            'email.required' => 'O email da barbearia é obrigatório.',
            'email.email' => 'O email fornecido não é válido.',
            'email.max' => 'O email deve ter no máximo 255 caracteres.',
            'phone.required' => 'O telefone é obrigatório.',
            'phone.string' => 'O telefone deve ser uma string válida.',
            'phone.max' => 'O telefone deve ter no máximo 20 caracteres.',
            'description.required' => 'A descrição da barbearia é obrigatória.',
            'description.string' => 'A descrição deve ser uma string válida.',
            'description.max' => 'A descrição deve ter no máximo 1500 caracteres.',
            'address.required' => 'O endereço é obrigatório.',
            'address.string' => 'O endereço deve ser uma string válida.',
            'address.max' => 'O endereço deve ter no máximo 255 caracteres.',
            'city.required' => 'A cidade é obrigatória.',
            'city.string' => 'A cidade deve ser uma string válida.',
            'city.max' => 'A cidade deve ter no máximo 100 caracteres.',
            'state.required' => 'O estado é obrigatório.',
            'state.string' => 'O estado deve ser uma string válida.',
            'state.max' => 'O estado deve ter no máximo 100 caracteres.',
            'zipcode.required' => 'O CEP é obrigatório.',
            'zipcode.string' => 'O CEP deve ser uma string válida.',
            'zipcode.max' => 'O CEP deve ter no máximo 10 caracteres.',
            'website.url' => 'O website deve ser um URL válido.',
            'location.string' => 'O endereço deve ser uma string válida.',
            'location.url' => 'O endereço deve ser um URL válido.',
            'instagram.url' => 'O link do instagram deve ser um URL válido.',
            'instagram.string' => 'O instagram deve ser uma string válida.',
            'latitude.numeric' => 'A latitude deve ser um número.',
            'longitude.numeric' => 'A longitude deve ser um número.',
            'rating.numeric' => 'A avaliação deve ser um número entre 0 e 5.',
            'rating.min' => 'A avaliação deve ser pelo menos 0.',
            'rating.max' => 'A avaliação não pode ser maior que 5.',
            'status.integer' => 'O status deve ser um número inteiro.',
            'terms_of_service.boolean' => 'Os termos de serviço devem ser aceitos.',
            'social_media_links.array' => 'Os links de redes sociais devem ser um array.',
            'logo.required' => 'A logo é obrigatória.',
            'logo.image' => 'A logo deve ser uma imagem válida (jpeg, png, bmp, gif, svg, ou webp).',
            'logo.max' => 'O nome do arquivo da logo deve ter no máximo 1255 caracteres.',
            'background_image.required' => 'A imagem de fundo é obrigatória.',
            'background_image.image' => 'A imagem de fundo deve ser uma imagem válida (jpeg, png, bmp, gif, svg, ou webp).',
            'background_image.max' => 'O nome do arquivo da imagem de fundo deve ter no máximo 1255 caracteres.',
        ];
    }

    public function store(Request $request)
    {
        try {
            // Verificar se o usuário está autenticado
            if (!Auth::check()) {
                Log::warning('Tentativa de cadastro sem autenticação.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Obter o usuário autenticado
            $user = Auth::user();
            Log::info('Usuário autenticado:', ['user_id' => $user->id, 'email' => $user->email]);

            // Validação dos dados da requisição
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'required|string|max:20',
                'description' => 'required|string|max:2500',
                'address' => 'required|string|max:255',
                'city' => 'required|string|max:100',
                'state' => 'required|string|max:100',
                'zipcode' => 'required|string|max:10',
                'website' => 'nullable|string',
                'location' => 'nullable|string',
                'instagram' => 'nullable|string',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'rating' => 'nullable|numeric|min:0|max:5', // Avaliação opcional
                'status' => 'nullable|integer',
                'terms_of_service' => 'nullable|boolean', // Termos de serviço opcional
                'social_media_links' => 'nullable|array', // Links de redes sociais opcionais
                'logo' => 'required|image|max:1255', // Logo obrigatória
                'background_image' => 'image|max:1255', // Background obrigatório
            ], $this->getValidationMessages());

            Log::info('Dados validados para a criação da barbearia.', $validatedData);

            // Criação da barbearia
            $barbershop = new Barbershop();
            $barbershop->fill($validatedData);
            $barbershop->user_id = $user->id; // Associar o user_id
            $barbershop->created_by = $user->id; // Registrar quem criou
            $barbershop->updated_by = $user->id; // Registrar quem atualizou inicialmente
            $barbershop->save();

            // Processar e salvar a logo
            if ($request->hasFile('logo')) {
                Log::info('Imagem de logo fornecida, processando...');

                // Definir o caminho do diretório público para imagens
                $destinationPath = public_path('images');

                // Gerar um nome único para a imagem
                $imageName = uniqid('logo_') . '.' . $request->file('logo')->getClientOriginalExtension();

                // Mover a imagem para o diretório público "images"
                $request->file('logo')->move($destinationPath, $imageName);

                // Redimensionar a imagem para 150x150
                $image = Image::make($destinationPath . '/' . $imageName);
                $image->fit(150, 150);
                $image->save();

                // Atualizar o caminho da logo no banco
                $barbershop->logo = 'images/' . $imageName;
                $barbershop->save();
            }

            // Processar e salvar o background (obrigatório)
            if ($request->hasFile('background_image')) {
                Log::info('Imagem de background fornecida, processando...');

                // Definir o caminho do diretório público para imagens
                $destinationPath = public_path('images');

                // Gerar um nome único para a imagem
                $imageName = uniqid('background_') . '.' . $request->file('background_image')->getClientOriginalExtension();

                // Mover a imagem para o diretório público "images"
                $request->file('background_image')->move($destinationPath, $imageName);

                // Redimensionar a imagem para 1920x600
                $image = Image::make($destinationPath . '/' . $imageName);
                $image->fit(1920, 600);
                $image->save();

                // Atualizar o caminho do background no banco
                $barbershop->background_image = 'images/' . $imageName;
                $barbershop->save();
            }


            Log::info('Barbearia criada com sucesso.', ['barbershop_id' => $barbershop->id]);

            $slug = Str::slug($request->input('name'));
            $count = Barbershop::where('slug', $slug)->count();
            if ($count > 0) {
                $slug = $slug . '-' . ($count + 1);
            }
            $barbershop->slug = $slug;

            $barbershop->user_id = Auth::user()->id;

            $barbershop->save();

            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'Create';
            $interaction->entity_id = $barbershop->id;
            $interaction->content = "O usuario " . $user->first_name . " criou  a barbearia" . $barbershop->name . ".";
            $interaction->entity_type = 'barbershop';
            $interaction->save();
            // Retornar sucesso
            return response()->json(['message' => 'Barbearia cadastrada com sucesso.', 'barbershop' => $barbershop], 201);

        } catch (ValidationException $e) {
            // Captura erros de validação e retorna como resposta JSON
            Log::error('Erro de validação ao cadastrar a barbearia.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            // Log do erro e retorno de mensagem genérica
            Log::error('Erro ao cadastrar barbearia: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao cadastrar a barbearia.'], 500);
        }
    }

    public function list(Request $request)
    {
        try {
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


    public function myBarbershops(Request $request)
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
    public function show($id)
    {
        try {
            // Obter o usuário autenticado
            $user = Auth::user();

            // Buscar a barbearia pelo ID
            $barbershop = Barbershop::find($id);

            // Verificar se a barbearia foi encontrada
            if (!$barbershop) {
                return response()->json(['error' => 'Barbearia não encontrada.'], 404);
            }

            // Decodificar o campo JSON para um array
            $barbersIds = json_decode($barbershop->barbers, true);

            // Verificar se o resultado da decodificação é um array
            if (!is_array($barbersIds)) {
                return response()->json(['error' => 'Formato de barbeiros inválido.'], 400);
            }

            // Buscar os barbeiros utilizando os IDs armazenados no campo barbers
            $barbers = User::whereIn('id', $barbersIds)->get();

            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'View';
            $interaction->entity_id = $barbershop->id;
            $interaction->content = "O usuario " . $user->first_name . " acessou a barbearia";
            $interaction->entity_type = 'barbershop';
            $interaction->save();

            // Retornar as informações da barbearia e os barbeiros
            return response()->json([
                'message' => 'Barbearia encontrada com sucesso.',
                'barbershop' => $barbershop,
                'barbers' => $barbers // Retorna os barbeiros associados à barbearia
            ], 200);

        } catch (\Exception $e) {
            // Log do erro e retorno de mensagem genérica
            Log::error('Erro ao buscar a barbearia: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao buscar a barbearia.'], 500);
        }
    }
    public function view($slug)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Buscar a barbearia pelo slug
            $barbershop = Barbershop::where('slug', $slug)->first();

            if (!$barbershop) {
                return response()->json(['error' => 'Barbearia não encontrada.'], 404);
            }

            // Obter os barbeiros associados à barbearia pela relação many-to-many
            $barbers = $barbershop->barbers()->with('user')->get();
            // Montar os dados detalhados dos barbeiros
            $barbersDetails = $barbers->map(function ($barber) {
                return [
                    'id' => $barber->id,
                    'user_id' => $barber->user_id,
                    'first_name' => $barber->user->first_name,
                    'email' => $barber->user->email,
                    'avatar' => $barber->user->avatar,
                    'user_name' => $barber->user->user_name,
                ];
            });

            // Buscar até 3 outras barbearias para apresentar como sugestões
            $otherBarbershops = Barbershop::where('slug', '!=', $slug)
                ->inRandomOrder()
                ->limit(3)
                ->get();

            // Criar um array de outras barbearias com as informações necessárias
            $otherBarbershopDetails = $otherBarbershops->map(function ($otherBarbershop) {
                return [
                    'name' => $otherBarbershop->name,
                    'slug' => $otherBarbershop->slug,
                    'logo' => $otherBarbershop->logo,
                ];
            });

            // Registrar a interação
            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'View';
            $interaction->entity_id = $barbershop->id;
            $interaction->content = "O usuário " . $user->first_name . " acessou a barbearia";
            $interaction->entity_type = 'barbershop';
            $interaction->save();

            // Buscar os itens relacionados à barbearia
            $items = $barbershop->items()->get();  // Corrigido para usar get() com parênteses

            // Retornar as informações da barbearia, barbeiros e outras barbearias
            return response()->json([
                'message' => 'Barbearia encontrada com sucesso.',
                'barbershop' => $barbershop,
                'items' => $items,
                'owner' => $barbershop->user,
                'barbers' => $barbersDetails, // Dados completos dos barbeiros
                'otherBarbershops' => $otherBarbershopDetails // Outras barbearias para navegação
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar a barbearia: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao buscar a barbearia.'], 500);
        }
    }


    public function update(Request $request, $id)
    {
        try {
            // Verificar se o usuário está autenticado
            if (!Auth::check()) {
                Log::warning('Tentativa de atualização sem autenticação.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Obter o usuário autenticado
            $user = Auth::user();
            Log::info('Usuário autenticado:', ['user_id' => $user->id, 'email' => $user->email]);

            // Verificar se a barbearia existe
            $barbershop = Barbershop::find($id);
            if (!$barbershop) {
                Log::warning('Barbearia não encontrada.', ['barbershop_id' => $id]);
                return response()->json(['error' => 'Barbearia não encontrada.'], 404);
            }

            // Validação dos dados da requisição
            $validatedData = $request->validate([
                'name' => 'nullable|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:20',
                'description' => 'nullable|string|max:2500',
                'address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'zipcode' => 'nullable|string|max:10',
                'website' => 'nullable|string',
                'instagram' => 'nullable|string',
                'rating' => 'nullable|numeric|min:0|max:5', // Avaliação opcional
                'status' => 'nullable|integer',
                'terms_of_service' => 'nullable|boolean', // Termos de serviço opcional
                'social_media_links' => 'nullable|array', // Links de redes sociais opcionais
                'logo' => 'nullable|image|max:1255', // Logo opcional
                'background_image' => 'nullable|image|max:1255', // Background opcional
                'location' => 'nullable|string',
            ], $this->getValidationMessages());

            Log::info('Dados validados para atualização da barbearia.', $validatedData);

            // Atualização dos campos da barbearia
            $barbershop->fill($validatedData);
            $barbershop->updated_by = $user->id; // Registrar quem atualizou
            $barbershop->save();

            // Processar e salvar a logo se fornecida
            if ($request->hasFile('logo')) {
                Log::info('Imagem de logo fornecida, processando...');

                // Definir o caminho do diretório público para imagens
                $destinationPath = public_path('images');

                // Gerar um nome único para a imagem
                $imageName = uniqid('logo_') . '.' . $request->file('logo')->getClientOriginalExtension();

                // Mover a imagem para o diretório público "images"
                $request->file('logo')->move($destinationPath, $imageName);

                // Redimensionar a imagem para 150x150
                $image = Image::make($destinationPath . '/' . $imageName);
                $image->fit(150, 150);
                $image->save();

                // Atualizar o caminho da logo no banco
                $barbershop->logo = 'images/' . $imageName;
                $barbershop->save();
            }

            // Processar e salvar o background se fornecido
            if ($request->hasFile('background_image')) {
                Log::info('Imagem de background fornecida, processando...');

                // Definir o caminho do diretório público para imagens
                $destinationPath = public_path('images');

                // Gerar um nome único para a imagem
                $imageName = uniqid('background_') . '.' . $request->file('background_image')->getClientOriginalExtension();

                // Mover a imagem para o diretório público "images"
                $request->file('background_image')->move($destinationPath, $imageName);

                // Redimensionar a imagem para 1920x600
                $image = Image::make($destinationPath . '/' . $imageName);
                $image->fit(1920, 600);
                $image->save();

                // Atualizar o caminho do background no banco
                $barbershop->background_image = 'images/' . $imageName;
                $barbershop->save();
            }

            Log::info('Barbearia atualizada com sucesso.', ['barbershop_id' => $barbershop->id]);

            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'Update';
            $interaction->entity_id = $barbershop->id;
            $interaction->content = "O usuário " . $user->first_name . " atualizou a barbearia " . $barbershop->name . ".";
            $interaction->entity_type = 'barbershop';
            $interaction->save();

            // Retornar sucesso
            return response()->json(['message' => 'Barbearia atualizada com sucesso.', 'barbershop' => $barbershop], 200);

        } catch (ValidationException $e) {
            // Captura erros de validação e retorna como resposta JSON
            Log::error('Erro de validação ao atualizar a barbearia.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

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
            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'Destroy';
            $interaction->entity_id = $barbershop->id;
            $interaction->content = "O usuario " . $user->first_name . " deletou a barbearia" . $barbershop->name . ".";
            $interaction->entity_type = 'barbershop';
            $interaction->save();
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
