<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\{Production,Event, Interaction};
use Illuminate\Support\Facades\File;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class ProductionController extends Controller
{
    // Função para obter mensagens de validação personalizadas
    protected function getValidationMessages()
    {
        return [
            'name.required' => 'O campo nome é obrigatório.',
            'type.required' => 'O campo tipo é obrigatório.',
            'establishment_type.required' => 'O campo tipo de estabelecimento é obrigatório.',
            'description.required' => 'O campo descrição é obrigatório.',
            'segments.required' => 'O campo segmentos é obrigatório.',
            // Adicione mensagens de validação para outros campos conforme necessário
        ];
    }

    public function list()
    {
        try {
            // Obter todas as produções cadastradas com o nome do produtor
            $productions = Production::with('user')->orderBy('created_at', 'desc')->get();

      
            // Retornar as produções com o nome do produtor como resposta em formato JSON
            return response()->json(['productions' => $productions], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao listar produções: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar as produções.'], 500);
        }
    }
    public function store(Request $request)
    {
        try {
            // Verificar se o usuário tem permissão para criar uma nova produção
            if (!Auth::user()->hasPermission('production_create')) {
                Log::error('Usuário não tem permissão para criar uma nova produção.');
                return response()->json(['error' => 'Você não tem permissão para criar uma nova produção.'], 403);
            }
            // Criar a nova produção
            $production = Production::create([
                'name' => $request->input('name'),
                'cnpj' => $request->input('cnpj'),
                'fantasy' => $request->input('fantasy'),
                'type' => $request->input('type'),
                'phone' => $request->input('phone'),
                'establishment_type' => $request->input('establishment_type'),
                'description' => $request->input('description'),
                'segments' => $request->input('segments'),
                'city' => $request->input('city'),
                'location' => $request->input('location'),
                'cep' => $request->input('cep'),
                'address' => $request->input('address'),
                'user_id' => Auth::user()->id,
                'is_featured' => (int) $request->input('is_featured'), // Convertendo para inteiro
                'is_published' => (int) $request->input('is_published'), // Convertendo para inteiro
                'is_approved' => (int) $request->input('is_approved'), // Convertendo para inteiro
                'is_cancelled' => (int) $request->input('is_cancelled'), // Convertendo para inteiro
                'additional_info' => $request->input('additional_info'),
                'facebook_url' => $request->input('facebook_url'),
                'website_url' => $request->input('website_url'),
                'twitter_url' => $request->input('twitter_url'),
                'instagram_url' => $request->input('instagram_url'),
                'youtube_url' => $request->input('youtube_url'),
                'other_information' => $request->input('other_information'),
                'ticket_price_min' => $request->input('ticket_price_min'),
                'ticket_price_max' => $request->input('ticket_price_max'),
                'total_tickets_sold' => $request->input('total_tickets_sold'),
                'total_tickets_available' => $request->input('total_tickets_available'),
            ]);

            if ($request->hasFile('logo')) {
                $imageLogoPath = $request->file('logo')->store('public/productions');
                $image = Image::make(storage_path('app/' . $imageLogoPath));
                $image->fit(150, 150);
                $image->save();

                $production->logo = str_replace('public/', '', $imageLogoPath);
                $production->save();
            }
            if ($request->hasFile('background')) {
                $imageBackgroundPath = $request->file('background')->store('public/productions');
                $image = Image::make(storage_path('app/' . $imageBackgroundPath));
                $image->fit(1920, 600);
                $image->save();

                $production->background = str_replace('public/', '', $imageBackgroundPath);
                $production->save();
            }

            $slug = Str::slug($request->input('name'));
            $count = Production::where('slug', $slug)->count();
            if ($count > 0) {
                $slug = $slug . '-' . ($count + 1);
            }
            $production->slug = $slug;

            $production->user_id = Auth::user()->id;

            $production->save();

            return response()->json(['message' => 'Nova produção cadastrada com sucesso.'], 201);

        } catch (\Exception $e) {
            Log::error('Erro ao cadastrar nova produção: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao cadastrar a nova produção.'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            // Verificar se a produção existe
            $production = Production::findOrFail($id);
    
            // Verificar se o usuário tem permissão para atualizar a produção
           
            if ($production->user_id === auth()->user()->id) {
                // Se for o fundador, ele pode atualizar independentemente da permissão
                $canUpdate = true;
            } else {
                // Se não for o fundador, verifique se ele tem permissão para atualizar produções
                $canUpdate = Auth::user()->hasPermission('production_update');
            }
    
            // Se não tiver permissão para atualizar e não for o fundador, retorne erro
            if (!$canUpdate) {
                Log::error('Usuário não tem permissão para atualizar esta produção.');
                return response()->json(['error' => 'Você não tem permissão para atualizar esta produção.'], 403);
            }
            // Atualizar os campos da produção
            $production->update([
                'name' => $request->input('name', $production->name),
                'cnpj' => $request->input('cnpj', $production->cnpj),
                'fantasy' => $request->input('fantasy', $production->fantasy),
                'type' => $request->input('type', $production->type),
                'phone' => $request->input('phone', $production->phone),
                'establishment_type' => $request->input('establishment_type', $production->establishment_type),
                'description' => $request->input('description', $production->description),
                'segments' => $request->input('segments', $production->segments),
                'city' => $request->input('city', $production->city),
                'location' => $request->input('location', $production->location),
                'cep' => $request->input('cep', $production->cep),
                'address' => $request->input('address', $production->address),
                'is_featured' => (int) $request->input('is_featured', $production->is_featured),
                'is_published' => (int) $request->input('is_published', $production->is_published),
                'is_approved' => (int) $request->input('is_approved', $production->is_approved),
                'is_cancelled' => (int) $request->input('is_cancelled', $production->is_cancelled),
                'additional_info' => $request->input('additional_info', $production->additional_info),
                'facebook_url' => $request->input('facebook_url', $production->facebook_url),
                'website_url' => $request->input('website_url', $production->website_url),
                'twitter_url' => $request->input('twitter_url', $production->twitter_url),
                'instagram_url' => $request->input('instagram_url', $production->instagram_url),
                'youtube_url' => $request->input('youtube_url', $production->youtube_url),
                'other_information' => $request->input('other_information', $production->other_information),
                'ticket_price_min' => $request->input('ticket_price_min', $production->ticket_price_min),
                'ticket_price_max' => $request->input('ticket_price_max', $production->ticket_price_max),
                'total_tickets_sold' => $request->input('total_tickets_sold', $production->total_tickets_sold),
                'total_tickets_available' => $request->input('total_tickets_available', $production->total_tickets_available),
            ]);
    
            // Atualizar a imagem do logo, se houver
            if ($request->hasFile('logo')) {
                $imageLogoPath = $request->file('logo')->store('public/productions');
                $image = Image::make(storage_path('app/' . $imageLogoPath));
                $image->fit(150, 150);
                $image->save();
    
                // Excluir a imagem anterior, se existir
                if ($production->logo) {
                    File::delete(storage_path('app/public/' . $production->logo));
                }
    
                $production->logo = str_replace('public/', '', $imageLogoPath);
                $production->save();
            }
            if ($request->hasFile('background')) {
                $imageBackgroundPath = $request->file('background')->store('public/productions');
                $image = Image::make(storage_path('app/' . $imageBackgroundPath));
                $image->fit(1920, 600);
                $image->save();
    
                // Excluir a imagem anterior, se existir
                if ($production->background) {
                    File::delete(storage_path('app/public/' . $production->background));
                }
    
                $production->background = str_replace('public/', '', $imageBackgroundPath);
                $production->save();
            }
    
            // Atualizar o slug da produção
            $slug = Str::slug($request->input('name'));
            $count = Production::where('slug', $slug)->where('id', '!=', $id)->count();
            if ($count > 0) {
                $slug = $slug . '-' . ($count + 1);
            }
            $production->slug = $slug;
            $production->save();
    
            return response()->json(['message' => 'Produção atualizada com sucesso.'], 200);
    
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar a produção: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao atualizar a produção.'], 500);
        }
    }

    public function show($id)
{
    try {
        // Encontrar a produção pelo ID
        $production = Production::where('id', $id)->with('user')->firstOrFail();
        $productionEvents = Event::where('production_id', $production->id)->get();
      
      

        // Retornar a produção como resposta em formato JSON
        return response()->json(['production' => $production,
                                'productionEvents'=> $productionEvents], 200);
    } catch (\Exception $e) {
        // Em caso de erro, retornar uma mensagem de erro em formato JSON
        return response()->json(['error' => 'Ocorreu um erro ao obter os detalhes da produção.', $e->getMessage()], 500);
    }
}
public function view($slug)
{
    try {
        // Log para verificar o slug fornecido
        \Log::info('Slug fornecido:', ['slug' => $slug]);

        $production = Production::where('slug', $slug)->with('user')->firstOrFail(); 
        $productionEvents = Event::where('production_id', $production->id)->get();
      
        // Log para verificar a produção encontrada pelo slug
        \Log::info('Produção encontrada:', ['production' => $production]);

        $productions = Production::all();
        $userHasLikedPost = false; // Valor padrão
        $currentDate = now(); // Obtém a data atual
        $nextevent = Event::where('start_date', '>', $currentDate)
            ->where('production_id', '=', $production->id)
            ->orderBy('start_date', 'asc')
            ->first();
        $radonevent = Event::where('start_date', '>', $currentDate)
            ->where('production_id', '!=', $production->id)
            ->inRandomOrder() // Ordena os resultados de forma aleatória
            ->first();
        $radonproduction = Production::where('id', '!=', $production->id)->inRandomOrder() // Ordena os resultados de forma aleatória
            ->first();

       

        // Verifica se o usuário está autenticado
        if (Auth::check()) {
            $user = Auth::user();
            $interaction = Interaction::where([
                'user_id' => $user->id,
                'entity_id' => $production->id,
                'entity_type' => 'production',
                'interaction_type' => 'like'
            ])->first();

            if ($interaction) {
                $userHasLikedPost = true;
            }

            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'view';
            $interaction->entity_id = $production->id;
            $interaction->entity_type = 'production';
            $interaction->save();
        }

        $views = Interaction::where([
            'entity_id' => $production->id,
            'entity_type' => 'production',
            'interaction_type' => 'view'
        ])->distinct('user_id')->count();
        
        
        return response()->json([
            'views' => $views,
            'radonevent' => $radonevent,
            'radonproduction' => $radonproduction,
            'nextevent' => $nextevent,
            'productions' => $productions,
            'production' => $production,
            'liked' => $userHasLikedPost,
            'productionEvents'=> $productionEvents
        ]);
    } catch (\Exception $exception) {
        // Log para registrar o erro
        \Log::error('Erro ao carregar informações da produção:', ['exception' => $exception]);
        
        return response()->json(['error' => 'Erro ao carregar informações da produção.'], 500);
    }
}
public function delete($id)
{
    try {
        // Verificar se a produção existe
        $production = Production::findOrFail($id);

        // Verificar se o usuário tem permissão para excluir a produção
        if (!Auth::user()->hasPermission('production_delete')) {
            Log::error('Usuário não tem permissão para excluir esta produção.');
            return response()->json(['error' => 'Você não tem permissão para excluir esta produção.'], 403);
        }
        if ($production->user_id !== auth()->user()->id) {
            Log::error('Usuário não tem permissão para atualizar esta produção.');
            return response()->json(['error' => 'Você não é o fundador desta produção.'], 403);
        }
        // Excluir a produção
        $production->delete();

        return response()->json(['message' => 'Produção excluída com sucesso.'], 200);

    } catch (\Exception $e) {
        Log::error('Erro ao excluir a produção: ' . $e->getMessage());
        return response()->json(['error' => 'Ocorreu um erro ao excluir a produção.'], 500);
    }
}

public function getCompanyInfo(Request $request)
{
    try {
        $cnpj = $request->input('cnpj');
        $response = Http::get("https://www.receitaws.com.br/v1/cnpj/$cnpj");

        // Verifica se a requisição foi bem-sucedida
        if ($response->successful()) {
            return $response->json();
        } else {
            // Log da resposta da requisição caso não seja bem-sucedida
            \Log::error('Erro ao obter informações da empresa: else ' . $response->json());
            // Se a requisição não foi bem-sucedida, retorna uma mensagem de erro
            return response()->json(['error' => 'Erro ao obter informações da empresa try'], $response->status());
        }
    } catch (\Exception $e) {
        // Em caso de exceção, retorna uma mensagem de erro
        \Log::error('Erro ao obter informações da empresa: ' . $e->getMessage());
        return response()->json(['error' => 'Erro ao obter informações da empresa catch'], 500);
    }
}



}