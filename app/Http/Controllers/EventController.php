<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\{Event, Production, Interaction};
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Validator;

class EventController extends Controller
{
    // Função para obter mensagens de validação personalizadas
    protected function getValidationMessages()
{
    return [
        'production_id.required' => 'O campo ID da produção é obrigatório.',
        'production_id.integer' => 'O campo ID da produção deve ser um número inteiro.',
        'title.required' => 'O campo título é obrigatório.',
        'title.string' => 'O campo título deve ser uma string.',
        'description.required' => 'O campo descrição é obrigatório.',
        'description.string' => 'O campo descrição deve ser uma string.',
        'image.required' => 'O banner do evento é obrigatório.',
        'image.image' => 'O arquivo enviado deve ser uma imagem.',
        'image.mimes' => 'O arquivo de imagem deve estar nos formatos: jpeg, png, jpg ou gif.',
        'image.max' => 'O tamanho máximo permitido para a imagem é de 2048 kilobytes.',
        'address.required' => 'O campo endereço é obrigatório.',
        'address.string' => 'O campo endereço deve ser uma string.',
        'start_date.required' => 'O campo data de início é obrigatório.',
        'start_date.date' => 'O campo data de início deve ser uma data válida.',
        'end_date.required' => 'O campo data de término é obrigatório.',
        'end_date.date' => 'O campo data de término deve ser uma data válida.',
        'end_date.after_or_equal' => 'A data de término deve ser igual ou após a data de início.',
        'venue.required' => 'O campo local é obrigatório.',
        'venue.string' => 'O campo local deve ser uma string.',
        'uf.required' => 'O campo UF é obrigatório.',
        'uf.string' => 'O campo UF deve ser uma string.',
        'establishment_type.string' => 'O campo tipo de estabelecimento deve ser uma string.',
        'slug.string' => 'O campo slug deve ser uma string.',
        'city.required' => 'O campo cidade é obrigatório.',
        'city.string' => 'O campo cidade deve ser uma string.',
        'state.required' => 'O campo estado é obrigatório.',
        'state.string' => 'O campo estado deve ser uma string.',
        'country.required' => 'O campo país é obrigatório.',
        'country.string' => 'O campo país deve ser uma string.',
        'location.string' => 'O campo localização deve ser uma string.',
        'cep.string' => 'O campo CEP deve ser uma string.',
        'latitude.string' => 'O campo latitude deve ser uma string.',
        'longitude.string' => 'O campo longitude deve ser uma string.',
        'is_featured.boolean' => 'O campo destaque deve ser um valor booleano.',
        'is_published.boolean' => 'O campo publicado deve ser um valor booleano.',
        'is_approved.boolean' => 'O campo aprovado deve ser um valor booleano.',
        'is_cancelled.boolean' => 'O campo cancelado deve ser um valor booleano.',
        'max_attendees.integer' => 'O campo número máximo de participantes deve ser um número inteiro.',
        'remaining_tickets.integer' => 'O campo ingressos restantes deve ser um número inteiro.',
        'extra_info.array' => 'O campo informações adicionais deve ser um array.',
        'agenda.array' => 'O campo agenda deve ser um array.',
        'menu.array' => 'O campo menu deve ser um array.',
        'additional_info.array' => 'O campo informações adicionais deve ser um array.',
        'facebook_url.string' => 'O campo URL do Facebook deve ser uma string.',
        'twitter_url.string' => 'O campo URL do Twitter deve ser uma string.',
        'instagram_url.string' => 'O campo URL do Instagram deve ser uma string.',
        'youtube_url.string' => 'O campo URL do YouTube deve ser uma string.',
        'contact_email.email' => 'O campo e-mail de contato deve ser um endereço de e-mail válido.',
        'contact_phone.string' => 'O campo telefone de contato deve ser uma string.',
        'website.string' => 'O campo website deve ser uma string.',
        'registration_link.string' => 'O campo link de registro deve ser uma string.',
        'organizer_name.string' => 'O campo nome do organizador deve ser uma string.',
        'organizer_email.email' => 'O campo e-mail do organizador deve ser um endereço de e-mail válido.',
        'organizer_phone.string' => 'O campo telefone do organizador deve ser uma string.',
        'organizer_description.string' => 'O campo descrição do organizador deve ser uma string.',
        'speaker_list.array' => 'O campo lista de palestrantes deve ser um array.',
        'sponsor_list.array' => 'O campo lista de patrocinadores deve ser um array.',
        'partners.array' => 'O campo parceiros deve ser um array.',
        'reviews.array' => 'O campo avaliações deve ser um array.',
        'rating.numeric' => 'O campo avaliação deve ser um número.',
        'rating.min' => 'O campo avaliação deve ser no mínimo :min.',
        'rating.max' => 'O campo avaliação deve ser no máximo :max.',
        'is_private.boolean' => 'O campo privado deve ser um valor booleano.',
        'requires_approval.boolean' => 'O campo requer aprovação deve ser um valor booleano.',
        'approval_message.string' => 'O campo mensagem de aprovação deve ser uma string.',
        'segments.array' => 'O campo segmentos deve ser um array.',
        'establishment_name.required' => 'O campo nome do estabelicimento deve ser preenchido',
        'establishment_name.string' => 'O campo nome do estabelicimento deve ser texto'
    ];
}

    public function list()
    {
        try {
            // Obter todos os eventos cadastrados
            $events = Event::all();

            // Retornar os eventos como resposta em formato JSON
            return response()->json(['events' => $events], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao listar eventos: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar os eventos.'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            // Validação de entrada
            $validator = Validator::make($request->all(), [
                'production_id' => 'required|integer',
                'title' => 'required|string',
                'description' => 'required|string',
                'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
                'address' => 'required|string',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'venue' => 'nullable|string',
                'uf' => 'required|string',
                'establishment_type' => 'required|string',
                'slug' => 'nullable|string',
                'city' => 'required|string',
                'state' => 'nullable|string',
                'country' => 'nullable|string',
                'location' => 'required|string',
                'cep' => 'required|string',
                'latitude' => 'nullable|string',
                'longitude' => 'nullable|string',
                'is_featured' => 'nullable|boolean',
                'is_published' => 'nullable|boolean',
                'is_approved' => 'nullable|boolean',
                'is_cancelled' => 'nullable|boolean',
                'max_attendees' => 'nullable|integer',
                'remaining_tickets' => 'nullable|integer',
                'extra_info' => 'nullable|array',
                'agenda' => 'nullable|array',
                'menu' => 'nullable|array',
                'additional_info' => 'nullable|array',
                'facebook_url' => 'nullable|string',
                'twitter_url' => 'nullable|string',
                'instagram_url' => 'nullable|string',
                'youtube_url' => 'nullable|string',
                'contact_email' => 'nullable|email',
                'contact_phone' => 'nullable|string',
                'website' => 'nullable|string',
                'registration_link' => 'nullable|string',
                'organizer_name' => 'nullable|string',
                'organizer_email' => 'nullable|email',
                'organizer_phone' => 'nullable|string',
                'organizer_description' => 'nullable|string',
                'speaker_list' => 'nullable|array',
                'sponsor_list' => 'nullable|array',
                'partners' => 'nullable|array',
                'reviews' => 'nullable|array',
                'rating' => 'nullable|numeric|min:0|max:5',
                'is_private' => 'nullable|boolean',
                'requires_approval' => 'nullable|boolean',
                'approval_message' => 'nullable|string',
                'segments' => 'nullable|array',
                'establishment_name' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => 'Erro de validação.', 'errors' => $validator->errors()], 422);
            }

            $event = new Event();

            // Atribuir cada atributo do evento
            $event->production_id = $request->input('production_id');
            $event->title = $request->input('title');
            $event->description = $request->input('description');
            $event->address = $request->input('address');
            $event->start_date = $request->input('start_date');
            $event->end_date = $request->input('end_date');
            $event->venue = $request->input('venue');
            $event->uf = $request->input('uf');
            $event->establishment_type = $request->input('establishment_type');
            $event->slug = $request->input('slug') ?? Str::slug($request->input('title'));
            $event->city = $request->input('city');
            $event->state = $request->input('state');
            $event->country = $request->input('country');
            $event->location = $request->input('location');
            $event->cep = $request->input('cep');
            $event->latitude = $request->input('latitude');
            $event->longitude = $request->input('longitude');
            $event->is_featured = $request->input('is_featured', false);
            $event->is_published = $request->input('is_published', false);
            $event->is_approved = $request->input('is_approved', false);
            $event->is_cancelled = $request->input('is_cancelled', false);
            $event->max_attendees = $request->input('max_attendees');
            $event->remaining_tickets = $request->input('remaining_tickets');
            $event->extra_info = $request->input('extra_info');
            $event->agenda = $request->input('agenda');
            $event->menu = $request->input('menu');
            $event->additional_info = $request->input('additional_info');
            $event->facebook_url = $request->input('facebook_url');
            $event->twitter_url = $request->input('twitter_url');
            $event->instagram_url = $request->input('instagram_url');
            $event->youtube_url = $request->input('youtube_url');
            $event->contact_email = $request->input('contact_email');
            $event->contact_phone = $request->input('contact_phone');
            $event->website = $request->input('website');
            $event->registration_link = $request->input('registration_link');
            $event->organizer_name = $request->input('organizer_name');
            $event->organizer_email = $request->input('organizer_email');
            $event->organizer_phone = $request->input('organizer_phone');
            $event->organizer_description = $request->input('organizer_description');
            $event->speaker_list = $request->input('speaker_list');
            $event->sponsor_list = $request->input('sponsor_list');
            $event->partners = $request->input('partners');
            $event->reviews = $request->input('reviews');
            $event->rating = $request->input('rating');
            $event->is_private = $request->input('is_private', false);
            $event->requires_approval = $request->input('requires_approval', false);
            $event->approval_message = $request->input('approval_message');
            $event->segments = $request->input('segments');
            $event->establishment_name = $request->input('establishment_name');

            // Salvar a imagem
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $imagePath = $image->store('public/events');
                $image = Image::make(storage_path('app/' . $imagePath));
                $image->fit(850, 450);
                $image->save();
                $event->image = str_replace('public/', '', $imagePath);
            }

            // Salvar o evento
            $event->save();

            return response()->json(['message' => 'Evento cadastrado com sucesso.'], 200);
        } catch (\Exception $e) {
            // Log de erro detalhado
            Log::error('Erro ao cadastrar evento: ' . $e->getMessage());

            // Retornar mensagem de erro
            return response()->json(['message' => 'Erro ao cadastrar evento.'], 500);
        }
    }
    public function update(Request $request, $id)
    {
        try {
            // Encontrar o evento pelo ID
            $event = Event::findOrFail($id);
    
           // Verificar se o usuário tem permissão para atualizar o evento associada a produção 
        if ($event->production->user_id !== auth()->user()->id) {
            // Se o usuário não for o proprietário da produção associada ao evento, verifique se ele tem permissão para atualizar eventos
            if (!auth()->user()->hasPermission('event_update')) {
                // Se o usuário não tiver permissão para atualizar eventos, retorne um erro
                Log::error('Usuário não tem permissão para atualizar este evento.');
                return response()->json(['error' => 'Você não tem permissão para atualizar este evento.'], 403);
            }
        }
        $event->update([
            'production_id' => $request->input('production_id', $event->production_id),
            'title' => $request->input('title', $event->title),
            'description' => $request->input('description', $event->description),
            'image' => $request->input('image', $event->image),
            'address' => $request->input('address', $event->address),
            'start_date' => $request->input('start_date', $event->start_date),
            'end_date' => $request->input('end_date', $event->end_date),
            'venue' => $request->input('venue', $event->venue),
            'uf' => $request->input('uf', $event->uf),
            'establishment_type' => $request->input('establishment_type', $event->establishment_type),
            'slug' => $request->input('slug', $event->slug),
            'city' => $request->input('city', $event->city),
            'state' => $request->input('state', $event->state),
            'country' => $request->input('country', $event->country),
            'location' => $request->input('location', $event->location),
            'cep' => $request->input('cep', $event->cep),
            'latitude' => $request->input('latitude', $event->latitude),
            'longitude' => $request->input('longitude', $event->longitude),
            'is_featured' => $request->input('is_featured', $event->is_featured),
            'is_published' => $request->input('is_published', $event->is_published),
            'is_approved' => $request->input('is_approved', $event->is_approved),
            'is_cancelled' => $request->input('is_cancelled', $event->is_cancelled),
            'max_attendees' => $request->input('max_attendees', $event->max_attendees),
            'remaining_tickets' => $request->input('remaining_tickets', $event->remaining_tickets),
            'extra_info' => $request->input('extra_info', $event->extra_info),
            'agenda' => $request->input('agenda', $event->agenda),
            'menu' => $request->input('menu', $event->menu),
            'additional_info' => $request->input('additional_info', $event->additional_info),
            'facebook_url' => $request->input('facebook_url', $event->facebook_url),
            'twitter_url' => $request->input('twitter_url', $event->twitter_url),
            'instagram_url' => $request->input('instagram_url', $event->instagram_url),
            'youtube_url' => $request->input('youtube_url', $event->youtube_url),
            'contact_email' => $request->input('contact_email', $event->contact_email),
            'contact_phone' => $request->input('contact_phone', $event->contact_phone),
            'website' => $request->input('website', $event->website),
            'registration_link' => $request->input('registration_link', $event->registration_link),
            'organizer_name' => $request->input('organizer_name', $event->organizer_name),
            'organizer_email' => $request->input('organizer_email', $event->organizer_email),
            'organizer_phone' => $request->input('organizer_phone', $event->organizer_phone),
            'organizer_description' => $request->input('organizer_description', $event->organizer_description),
            'speaker_list' => $request->input('speaker_list', $event->speaker_list),
            'sponsor_list' => $request->input('sponsor_list', $event->sponsor_list),
            'partners' => $request->input('partners', $event->partners),
            'reviews' => $request->input('reviews', $event->reviews),
            'rating' => $request->input('rating', $event->rating),
            'is_private' => $request->input('is_private', $event->is_private),
            'requires_approval' => $request->input('requires_approval', $event->requires_approval),
            'approval_message' => $request->input('approval_message', $event->approval_message),
            'segments' => $request->input('segments', $event->segments),
            'establishment_name' => $request->input('establishment_name', $event->establishment_name)
        ]);
        
    
            // Salvar a imagem, se estiver presente no request
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $imagePath = $image->store('public/events');
                $image = Image::make(storage_path('app/' . $imagePath));
                $image->fit(850, 450);
                $image->save();
                $event->image = str_replace('public/', '', $imagePath);
            }
    
            // Salvar as mudanças
            $event->save();
    
            return response()->json(['message' => 'Evento atualizado com sucesso.', 'event' => $event], 200);
        } catch (\Exception $e) {
            // Log de erro detalhado
            Log::error('Erro ao atualizar evento: ' . $e->getMessage());
    
            // Retornar mensagem de erro
            return response()->json(['message' => 'Erro ao atualizar evento.'], 500);
        }
    }
    public function show($id)
{
    try {
        // Encontrar o evento pelo ID, carregando a relação 'production'
        $event = Event::with('production')->findOrFail($id);
  
        // Retornar o evento como resposta em formato JSON
        return response()->json(['event' => $event], 200);
    } catch (\Exception $e) {
        // Em caso de erro, retornar uma mensagem de erro em formato JSON
        return response()->json(['error' => 'Ocorreu um erro ao obter os detalhes do evento.', 'message' => $e->getMessage()], 500);
    }
}

public function view($slug)
{
    $event = Event::where('slug', $slug)->with('tickets')->firstOrFail();
    
    $events = Event::orderBy('start_date', 'desc')
        ->where('production_id', $event->production_id) // Filtrando pela mesma produção
        ->get();
    
    $userHasLikedPost = false; // Default value
    $userHdesconfirmedPost = false; // Default value
    
    // Check if the user is authenticated
    if (Auth::check()) {
        $user = Auth::user();
        
        $interaction = new Interaction();
        $interaction->user_id =  $user->id;
        $interaction->interaction_type = 'view';
        $interaction->entity_id = $event->id;
        $interaction->entity_type = 'event';
        $interaction->save();

        $interactionLike = Interaction::where([
            'user_id' => $user->id,
            'entity_id' => $event->id,
            'entity_type' => 'event',
            'interaction_type' => 'like'
        ])->first();
        
        $interactionConfirm = Interaction::where([
            'user_id' => $user->id,
            'entity_id' => $event->id,
            'entity_type' => 'event',
            'interaction_type' => 'confirm'
        ])->first();
        
        $userHasLikedPost = $interactionLike ? true : false;
        $userHdesconfirmedPost = $interactionConfirm ? true : false;
    }

    return response()->json([
        'event' => $event,
        'events' => $events,
        'tickets' => $event->tickets, // Incluindo os ingressos na resposta
        'liked' => $userHasLikedPost,
        'confirmed' => $userHdesconfirmedPost,
    ], 200);
}

    public function delete($id)
    {
        try {
            // Encontrar o evento pelo ID
            $event = Event::findOrFail($id);

            // Excluir o evento
            $event->delete();

            // Retornar mensagem de sucesso em formato JSON
            return response()->json(['message' => 'Evento excluído com sucesso.'], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao excluir o evento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao excluir o evento.'], 500);
        }
    }
    public function myEvents()
    {
        try {
            // Verificar se o usuário está autenticado
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }
    
            // Obter o usuário autenticado
            $user = Auth::user();
    
            // Carregar os eventos associados ao usuário através das produções
            $events = $user->events()->with('production')->get();
    
            // Retornar os eventos como resposta em formato JSON
            return response()->json(['events' => $events], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao listar eventos do usuário: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar os eventos do usuário.'], 500);
        }
    }
    
    
}
