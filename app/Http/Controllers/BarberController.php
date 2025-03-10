<?php

namespace App\Http\Controllers;

use App\Models\{Barbershop, Barber, Interaction, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewBarberMail;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;

class BarberController extends Controller
{
    // Mensagens de validação personalizadas
    protected function getValidationMessages()
    {
        return [
            'user_id.required' => 'O ID do usuário (gerente da barbearia) é obrigatório.',
            'user_id.exists' => 'O usuário selecionado não existe.',
            'first_name.required' => 'O nome é obrigatório.',
            'last_name.required' => 'O sobrenome é obrigatório.',
            'email.required' => 'O email é obrigatório.',
            'email.email' => 'O email fornecido não é válido.',
            'email.unique' => 'Já existe um barbeiro com este email.',
            'slug.unique' => 'Já existe um barbeiro com esse slug.',
            'phone.required' => 'O telefone é obrigatório.',
            'phone.regex' => 'O telefone fornecido não é válido.',
            'barbershops.array' => 'As barbearias devem ser informadas em formato de lista.',
            'avatar.image' => 'A imagem de avatar deve ser uma imagem válida (jpg, jpeg, png).',
            'bio.max' => 'A biografia deve ter no máximo 500 caracteres.',
            'specialties.max' => 'As especialidades devem ter no máximo 500 caracteres.',
            'experience_years.integer' => 'O número de anos de experiência deve ser um número inteiro.',
            'working_hours.array' => 'Os horários de trabalho devem ser informados em formato de lista.',
            'status.in' => 'O status deve ser um dos seguintes: ativo, inativo ou de licença.',
            'rating.numeric' => 'A avaliação deve ser um número válido.',
            'price_range.string' => 'A faixa de preço deve ser uma string.',
            'social_media_links.array' => 'Os links para redes sociais devem ser informados em formato de lista.',
            'languages.string' => 'Os idiomas devem ser informados como uma string.',
            'certifications.array' => 'Os certificados devem ser informados em formato de lista.',
        ];
    }


    public function list(Request $request)
    {
        try {
            // Obter todos os barbeiros com a paginação e carregar os dados do usuário associado
            $barbers = Barber::with('user') // Carrega o relacionamento 'user' de cada barber
                ->paginate(10); // Paginação de 10 barbeiros por página

            // Retornar a lista de barbeiros com os dados do usuário
            return response()->json([
                'message' => 'Barbeiros listados com sucesso.',
                'barbers' => $barbers, // Lista de barbeiros com dados do usuário incluídos
            ], 200);

        } catch (\Exception $e) {
            // Log do erro e retorno de mensagem genérica
            Log::error('Erro ao listar barbeiros: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar os barbeiros.'], 500);
        }
    }
    public function store(Request $request)
    {
        // Validação do email
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'barbershop_id' => 'required|exists:barbershops,id',  // Valida se a barbearia existe
        ]);

        // Buscar o usuário pelo email
        $user = User::where('email', $request->email)->first();

        // Verificar se o usuário já tem um barbeiro registrado
        $barber = Barber::where('user_id', $user->id)->first();

        // Se o usuário não for um barbeiro, criar um novo registro
        if (!$barber) {
            // Criar o barbeiro
            $barber = Barber::create([
                'user_id' => $user->id,
            ]);
        }

        // Associar o barbeiro à barbearia
        $barbershop = Barbershop::find($request->barbershop_id);

        // Verificar se o barbeiro já está associado à barbearia
        if ($barbershop->barbers()->where('barber_id', $barber->id)->exists()) {
            return response()->json([
                'message' => 'Este barbeiro já está associado a esta barbearia.',
            ], 400); // 400 é o código HTTP para erro de requisição
        }

        // Adicionar o barbeiro à barbearia (associação)
        $barbershop->barbers()->attach($barber->id);

        // Enviar email para o usuário com a confirmação
        Mail::to($user->email)->send(new NewBarberMail($barbershop, $user));

        // Obter a lista de barbeiros da barbearia
        $barbers = $barbershop->barbers;

        // Retornar a resposta
        return response()->json([
            'message' => 'Barbeiro associado com sucesso!',
            'barbers' => $barbers,  // Lista de barbeiros da barbearia
            'barbershop' => $barbershop,  // Dados da barbearia
        ]);
    }


    public function update(Request $request, $id)
    {
        try {
            // Verificar se o usuário está autenticado
            if (!Auth::check()) {
                Log::warning('Tentativa de atualização de barbeiro sem autenticação.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Buscar o barbeiro pelo ID
            $barber = Barber::find($id);
            if (!$barber) {
                Log::warning('Barbeiro não encontrado: ' . $id);
                return response()->json(['error' => 'Barbeiro não encontrado.'], 404);
            }

            // Log de dados recebidos para atualização
            Log::info('Dados recebidos para atualizar barbeiro:', ['request_data' => $request->all()]);

            // Validação dos dados recebidos
            $validatedData = $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'email' => 'required|email|unique:barbers,email,' . $barber->id,
                'phone' => 'required|regex:/^\+?[1-9]\d{1,14}$/',
                'barbershops' => 'nullable|array',
                'barbershops.*' => 'exists:barbershops,id', // Verifica se as barbearias existem
                'avatar' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
                'bio' => 'nullable|string|max:500',
                'specialties' => 'nullable|string|max:500',
                'experience_years' => 'nullable|integer',
                'working_hours' => 'nullable|array',
                'status' => 'nullable|in:ativo,inativo,licença',
                'rating' => 'nullable|numeric|min:1|max:5',
                'price_range' => 'nullable|string',
                'social_media_links' => 'nullable|array',
                'languages' => 'nullable|string',
                'certifications' => 'nullable|array',
            ], $this->getValidationMessages());

            // Atualizar os dados do barbeiro
            $barber->first_name = $validatedData['first_name'];
            $barber->last_name = $validatedData['last_name'];
            $barber->email = $validatedData['email'];
            $barber->phone = $validatedData['phone'];
            $barber->bio = $validatedData['bio'] ?? $barber->bio;
            $barber->specialties = $validatedData['specialties'] ?? $barber->specialties;
            $barber->experience_years = $validatedData['experience_years'] ?? $barber->experience_years;
            $barber->working_hours = $validatedData['working_hours'] ?? $barber->working_hours;
            $barber->status = $validatedData['status'] ?? $barber->status;
            $barber->rating = $validatedData['rating'] ?? $barber->rating;
            $barber->price_range = $validatedData['price_range'] ?? $barber->price_range;
            $barber->social_media_links = $validatedData['social_media_links'] ?? $barber->social_media_links;
            $barber->languages = $validatedData['languages'] ?? $barber->languages;
            $barber->certifications = $validatedData['certifications'] ?? $barber->certifications;

            // Atualizar a imagem de avatar, se fornecida
            if ($request->hasFile('avatar')) {
                Log::info('Imagem de avatar fornecida, processando...');

                // Definindo o caminho do diretório público para imagens
                $destinationPath = public_path('images');

                // Gerar um nome único para a imagem
                $imageName = uniqid('avatar_') . '.' . $request->file('avatar')->getClientOriginalExtension();

                // Mover a imagem para o diretório público "images"
                $request->file('avatar')->move($destinationPath, $imageName);

                // Redimensionar a imagem para 150x150
                $image = Image::make($destinationPath . '/' . $imageName);
                $image->fit(150, 150);
                $image->save();

                // Atualizar o caminho da imagem no banco
                $barber->avatar = 'images/' . $imageName;
            }

            // Atualizar as barbearias associadas
            if (!empty($validatedData['barbershops'])) {
                // Caso haja relacionamento de muitos para muitos, pode ser necessário usar sync()
                // $barber->barbershops()->sync($validatedData['barbershops']);
                $barber->barbershops = json_encode($validatedData['barbershops']);
            }

            // Salvar as alterações no banco de dados
            $barber->save();

            // Log de sucesso
            Log::info('Barbeiro atualizado com sucesso: ' . $barber->id);

            // Resposta de sucesso
            return response()->json(['message' => 'Barbeiro atualizado com sucesso.', 'barber' => $barber], 200);

        } catch (\Exception $e) {
            // Log do erro e retorno de erro genérico
            Log::error('Erro ao atualizar barbeiro: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao atualizar o barbeiro.'], 500);
        }
    }
    public function view($user_name)
    {
        try {
            // Buscar o usuário pelo user_name
            $user = User::where('user_name', $user_name)->first();

            if (!$user) {
                Log::warning('Usuário não encontrado com o nome de usuário: ' . $user_name);
                return response()->json(['error' => 'Usuário não encontrado.'], 404);
            }

            // Buscar o barbeiro associado ao usuário
            $barber = Barber::where('user_id', $user->id)->with('barbershops')->first();

            if (!$barber) {
                Log::warning('O usuário não é um barbeiro: ' . $user_name);
                return response()->json(['error' => 'Usuário não encontrado como barbeiro.'], 404);
            }

            // Recuperar as barbearias associadas ao barbeiro
            $barbershops = $barber->barbershops;

            $otherBarbers = User::where('user_name', '!=', $user_name)
                ->inRandomOrder()
                ->limit(3)
                ->get();

            // Criar um array de outras barbearias com as informações necessárias
            $otherBarbershopDetails = $otherBarbers->map(function ($otherBarber) {
                return [
                    'name' => $otherBarber->first_name,
                    'slug' => $otherBarber->slug,
                    'avatar' => $otherBarber->avatar,
                ];
            });

            return response()->json([
                'message' => 'Barbeiro encontrado com sucesso.',
                'barber' => $barber,
                'user' => $user,
                'barbershops' => $barbershops,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar barbeiro pelo nome de usuário: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao buscar o barbeiro.'], 500);
        }
    }
    public function destroy(Request $request)
    {
        // Log inicial para identificar a requisição recebida
        Log::info('Iniciando a remoção de um barbeiro de uma barbearia.', [
            'barber_id' => $request->barber_id,
            'barbershop_id' => $request->barbershop_id,
        ]);

        // Validação dos dados de entrada
        $request->validate([
            'barber_id' => 'required|exists:barbers,id', // Verifica se o barbeiro existe
            'barbershop_id' => 'required|exists:barbershops,id', // Verifica se a barbearia existe
        ]);

        Log::info('Dados validados com sucesso.');

        // Buscar o barbeiro e a barbearia
        $barber = Barber::find($request->barber_id);
        $barbershop = Barbershop::find($request->barbershop_id);

        if (!$barber || !$barbershop) {
            Log::error('Barbeiro ou barbearia não encontrados.', [
                'barber_id' => $request->barber_id,
                'barbershop_id' => $request->barbershop_id,
            ]);
            return response()->json([
                'message' => 'Barbeiro ou barbearia não encontrados.'
            ], 404);
        }

        Log::info('Barbeiro e barbearia encontrados.', [
            'barber' => $barber,
            'barbershop' => $barbershop,
        ]);

        // Verificar se o barbeiro está associado à barbearia
        if (!$barbershop->barbers()->where('barber_id', $barber->id)->exists()) {
            Log::warning('Tentativa de desvincular um barbeiro que não está associado à barbearia.', [
                'barber_id' => $barber->id,
                'barbershop_id' => $barbershop->id,
            ]);

            return response()->json([
                'message' => 'Este barbeiro não está associado a esta barbearia.',
            ], 400);
        }

        Log::info('O barbeiro está associado à barbearia.');

        // Remover o barbeiro da barbearia
        $barbershop->barbers()->detach($barber->id);
        Log::info('Barbeiro removido da barbearia.', [
            'barber_id' => $barber->id,
            'barbershop_id' => $barbershop->id,
        ]);

        // Verificar se o barbeiro ainda está associado a outras barbearias
        if ($barber->barbershops()->count() === 0) {
            Log::info('O barbeiro não está associado a nenhuma outra barbearia. Excluindo o registro.', [
                'barber_id' => $barber->id,
            ]);
            $barber->delete();
            Log::info('Barbeiro excluído com sucesso.', [
                'barber_id' => $barber->id,
            ]);
        } else {
            Log::info('O barbeiro ainda está associado a outras barbearias. Nenhuma exclusão realizada.', [
                'barber_id' => $barber->id,
            ]);
        }

        // Obter a lista atualizada de barbeiros da barbearia
        $barbers = $barbershop->barbers;

        Log::info('Lista de barbeiros atualizada para a barbearia.', [
            'barbershop_id' => $barbershop->id,
            'barbers' => $barbers,
        ]);

        // Retornar a resposta
        return response()->json([
            'message' => 'Barbeiro desvinculado com sucesso!',
            'barbers' => $barbers,  // Lista atualizada de barbeiros da barbearia
            'barbershop' => $barbershop,  // Dados da barbearia
        ]);
    }
    public function showById($id)
    {
        try {
            // Buscar o barbeiro pelo ID, carregando os relacionamentos com o usuário e as barbearias associadas
            $barber = Barber::with('user', 'barbershops')->find($id);
            if (!$barber) {
                Log::warning('Barbeiro não encontrado: ' . $id);
                return response()->json(['error' => 'Barbeiro não encontrado.'], 404);
            }
            return response()->json([
                'message' => 'Barbeiro encontrado com sucesso.',
                'barber'  => $barber
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar barbeiro pelo ID: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao buscar o barbeiro.'], 500);
        }
    }
    
}
