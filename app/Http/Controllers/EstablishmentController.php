<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\Interaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Str;

class EstablishmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api')->except(['view', 'listByCategory', 'show', 'list']);
    }

    protected function getValidationMessages()
    {
        return [
            'name.required' => 'O nome do estabelecimento � obrigat�rio.',
            'name.string' => 'O nome deve ser uma string v�lida.',
            'name.max' => 'O nome deve ter no m�ximo 255 caracteres.',
            'email.email' => 'O email fornecido n�o � v�lido.',
            'email.max' => 'O email deve ter no m�ximo 255 caracteres.',
            'phone.string' => 'O telefone deve ser uma string v�lida.',
            'phone.max' => 'O telefone deve ter no m�ximo 20 caracteres.',
            'description.string' => 'A descri��o deve ser uma string v�lida.',
            'description.max' => 'A descri��o deve ter no m�ximo 2500 caracteres.',
            'address.string' => 'O endere�o deve ser uma string v�lida.',
            'address.max' => 'O endere�o deve ter no m�ximo 255 caracteres.',
            'city.string' => 'A cidade deve ser uma string v�lida.',
            'city.max' => 'A cidade deve ter no m�ximo 100 caracteres.',
            'cep.string' => 'O CEP deve ser uma string v�lida.',
            'cep.max' => 'O CEP deve ter no m�ximo 10 caracteres.',
            'website_url.url' => 'O website deve ser um URL v�lido.',
            'location.string' => 'A localiza��o deve ser uma string v�lida.',
            'instagram_url.url' => 'O link do instagram deve ser um URL v�lido.',
            'facebook_url.url' => 'O link do Facebook deve ser um URL v�lido.',
            'twitter_url.url' => 'O link do Twitter deve ser um URL v�lido.',
            'youtube_url.url' => 'O link do YouTube deve ser um URL v�lido.',
            'segments.array' => 'Os segmentos devem ser enviados como array.',
            'segments.*.string' => 'Cada segmento deve ser uma string.',
            'logo.required' => 'A logo � obrigat�ria.',
            'logo.image' => 'A logo deve ser uma imagem v�lida.',
            'logo.max' => 'A logo deve ter no m�ximo 2048 KB.',
            'background.image' => 'A imagem de fundo deve ser uma imagem v�lida.',
        ];
    }

    public function store(Request $request)
    {
        try {
            if (!Auth::check()) {
                Log::warning('Tentativa de cadastro sem autentica��o.');
                return response()->json(['error' => 'Usu�rio n�o autenticado.'], 401);
            }

            $user = Auth::user();
            $establishmentCount = Establishment::where('user_id', $user->id)->count();

            if ($establishmentCount >= 1 && !$user->hasPermission('establishment_create')) {
                return response()->json([
                    'error' => 'Voc� j� possui um estabelecimento cadastrado. Para cadastrar mais, solicite permiss�o.'
                ], 403);
            }

            Log::info('Usu�rio autenticado:', ['user_id' => $user->id, 'email' => $user->email]);

            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'fantasy' => 'nullable|string|max:255',
                'cnpj' => 'nullable|string|max:18',
                'type' => 'nullable|string|max:50',
                'category' => 'nullable|string|max:50',
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'description' => 'nullable|string|max:2500',
                'additional_info' => 'nullable|string|max:1000',
                'address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'cep' => 'nullable|string|max:10',
                'location' => 'nullable|string',
                'website_url' => 'nullable|url|max:255',
                'facebook_url' => 'nullable|url|max:255',
                'instagram_url' => 'nullable|url|max:255',
                'twitter_url' => 'nullable|url|max:255',
                'youtube_url' => 'nullable|url|max:255',
                'segments' => 'nullable|array',
                'segments.*' => 'string',
                'logo' => 'required|image|max:2048',
                'background' => 'nullable|image|max:4096',
            ], $this->getValidationMessages());

            $establishment = new Establishment();
            $establishment->fill($validatedData);
            $establishment->user_id = $user->id;
            $establishment->slug = Str::slug($establishment->fantasy ?? $establishment->name);
            if (is_array($request->segments)) {
                $establishment->segments = json_encode($request->segments);
            }
            $establishment->save();

            if ($request->hasFile('logo')) {
                Log::info('Imagem de logo fornecida, processando...');
                $destinationPath = public_path('images');
                $imageName = uniqid('logo_') . '.' . $request->file('logo')->getClientOriginalExtension();
                $request->file('logo')->move($destinationPath, $imageName);
                $image = Image::make($destinationPath . '/' . $imageName)->fit(150, 150)->save();
                $establishment->logo = 'images/' . $imageName;
                $establishment->save();
            }

            if ($request->hasFile('background')) {
                Log::info('Imagem de fundo fornecida, processando...');
                $destinationPath = public_path('images');
                $imageName = uniqid('background_') . '.' . $request->file('background')->getClientOriginalExtension();
                $request->file('background')->move($destinationPath, $imageName);
                $image = Image::make($destinationPath . '/' . $imageName)->fit(1920, 600)->save();
                $establishment->background = 'images/' . $imageName;
                $establishment->save();
            }

            Interaction::create([
                'user_id' => $user->id,
                'interaction_type' => 'Create',
                'entity_id' => $establishment->id,
                'entity_type' => 'establishment',
                'content' => "O usu�rio {$user->first_name} criou o estabelecimento {$establishment->name}.",
            ]);

            return response()->json([
                'message' => 'Estabelecimento cadastrado com sucesso.',
                'establishment' => $establishment
            ], 201);

        } catch (ValidationException $e) {
            Log::error('Erro de valida��o ao cadastrar o estabelecimento.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            Log::error('Erro ao cadastrar estabelecimento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao cadastrar o estabelecimento.'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usu�rio n�o autenticado.'], 401);
            }

            $user = Auth::user();

            try {
                $establishment = Establishment::findOrFail($id);
            } catch (ModelNotFoundException $e) {
                return response()->json(['error' => 'Estabelecimento n�o encontrado com o ID fornecido.'], 404);
            }

            if ($establishment->user_id !== $user->id) {
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            $validatedData = $request->validate([
                'name'           => 'sometimes|required|string|max:255',
                'email'          => 'nullable|email|max:255',
                'phone'          => 'nullable|string|max:20',
                'description'    => 'nullable|string|max:2500',
                'address'        => 'nullable|string|max:255',
                'city'           => 'nullable|string|max:100',
                'cep'            => 'nullable|string|max:10',
                'website_url'    => 'nullable|string',
                'location'       => 'nullable|string',
                'instagram_url'  => 'nullable|string',
                'logo'           => 'nullable|image|max:1255',
                'background'     => 'nullable|image|max:1255',
            ], $this->getValidationMessages());

            $establishment->fill($validatedData);
            $establishment->updated_by = $user->id;

            if ($request->hasFile('logo')) {
                $dest = public_path('images');
                $name = uniqid('logo_').'.'.$request->file('logo')->getClientOriginalExtension();
                $request->file('logo')->move($dest, $name);
                Image::make("$dest/$name")->fit(150, 150)->save();
                $establishment->logo = "images/$name";
            }

            if ($request->hasFile('background')) {
                $dest = public_path('images');
                $name = uniqid('background_').'.'.$request->file('background')->getClientOriginalExtension();
                $request->file('background')->move($dest, $name);
                Image::make("$dest/$name")->fit(1920, 600)->save();
                $establishment->background = "images/$name";
            }

            if ($request->filled('name')) {
                $slugBase = Str::slug($request->input('name'));
                $count = Establishment::where('slug', $slugBase)
                    ->where('id', '!=', $establishment->id)
                    ->count();
                $establishment->slug = $count
                    ? "{$slugBase}-".($count + 1)
                    : $slugBase;
            }

            $establishment->save();

            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'Update';
            $interaction->entity_type = 'establishment';
            $interaction->entity_id = $establishment->id;
            $interaction->content = "O usuário {$user->first_name} atualizou o estabelecimento {$establishment->name}.";
            $interaction->save();

            return response()->json([
                'message' => 'Estabelecimento atualizado com sucesso.',
                'establishment' => $establishment,
            ], 200);
        } catch (ValidationException $e) {
            Log::error('Erro de valida��o ao atualizar o estabelecimento.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar estabelecimento: '.$e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao atualizar o estabelecimento.'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usu�rio n�o autenticado.'], 401);
            }

            $user = Auth::user();

            if (!$user->hasPermission('establishment_destroy')) {
                return response()->json(['error' => 'Voc� n�o tem permiss�o para deletar estabelecimentos.'], 403);
            }

            $establishment = Establishment::findOrFail($id);

            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'Destroy';
            $interaction->entity_id = $establishment->id;
            $interaction->content = "O usu�rio " . $user->first_name . " deletou o estabelecimento " . $establishment->name . ".";
            $interaction->entity_type = 'establishment';
            $interaction->save();

            $establishment->delete();

            return response()->json(['message' => 'Estabelecimento exclu�do com sucesso.'], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Estabelecimento n�o encontrado.'], 404);

        } catch (\Exception $e) {
            Log::error('Erro ao excluir estabelecimento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao excluir o estabelecimento.'], 500);
        }
    }

    public function listByUser(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usu�rio n�o autenticado.'], 401);
            }

            $user = Auth::user();
            $query = Establishment::where('user_id', $user->id);

            // Filtra por categoria se informada
            if ($request->has('category')) {
                $query->where('category', $request->category);
            }

            $establishments = $query->paginate(10);

            return response()->json([
                'message' => 'Estabelecimentos listados com sucesso.',
                'establishments' => $establishments,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao listar estabelecimentos do usu�rio: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar seus estabelecimentos.'], 500);
        }
    }

  public function view($slug)
{
    try {
        $user = Auth::user();

        $establishment = Establishment::where('slug', $slug)->first();
        if (!$establishment) {
            return response()->json(['error' => 'Estabelecimento não encontrado.'], 404);
        }

        // Itens do cardápio
        $items = $establishment->items()->get();

        // Outros estabelecimentos para sugestão
        $otherEstablishments = Establishment::where('slug', '!=', $slug)
            ->inRandomOrder()
            ->limit(3)
            ->get(['name', 'slug', 'logo']);

        // Colaboradores (employers) com dados do usuário
        $collaborators = Employer::where('establishment_id', $establishment->id)
            ->with('user:id,first_name,last_name,user_name,avatar,email')
            ->get();

        // Registrar visualização (se autenticado)
        if ($user) {
            Interaction::create([
                'user_id' => $user->id,
                'interaction_type' => 'View',
                'entity_id' => $establishment->id,
                'entity_type' => 'establishment',
                'content' => "Usuário {$user->first_name} acessou {$establishment->name}"
            ]);
        }

        return response()->json([
            'message' => 'Estabelecimento encontrado com sucesso.',
            'establishment' => $establishment,
            'items' => $items,
            'owner' => $establishment->user,
            'collaborators' => $collaborators,
            'otherEstablishments' => $otherEstablishments,
        ], 200);

    } catch (\Exception $e) {
        Log::error('Erro ao buscar estabelecimento: ' . $e->getMessage());
        return response()->json(['error' => 'Ocorreu um erro ao buscar o estabelecimento.'], 500);
    }
}
    public function show($id)
    {
        try {
            $user = Auth::user();

            $establishment = Establishment::find($id);

            if (!$establishment) {
                return response()->json(['error' => 'Estabelecimento n�o encontrado.'], 404);
            }

            Interaction::create([
                'user_id' => $user?->id,
                'entity_id' => $establishment->id,
                'entity_type' => 'establishment',
                'interaction_type' => 'View',
                'content' => 'O usu�rio ' . ($user?->first_name ?? 'an�nimo') . ' acessou o estabelecimento.',
            ]);

            return response()->json([
                'message' => 'Estabelecimento encontrado com sucesso.',
                'establishment' => $establishment,
                'owner' => $establishment->user,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar o estabelecimento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao buscar o estabelecimento.'], 500);
        }
    }
    public function list(Request $request)
    {
        try {
            $establishments = Establishment::paginate(10);

            return response()->json([
                'message' => 'Estabelecimentos listados com sucesso.',
                'establishments' => $establishments,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao listar estabelecimentos: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar os estabelecimentos.'], 500);
        }
    }
    public function listByCategory($category)
    {
        $query = Establishment::query();
        if ($category) {
            $query->where('category', $category);
        }
        $establishments = $query->paginate(10);
        return response()->json([
            'message' => 'Estabelecimentos listados por categoria com sucesso.',
            'establishments' => $establishments
        ], 200);
    }

    public function listMyByCategory(Request $request, $category)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usu�rio n�o autenticado.'], 401);
            }

            $user = Auth::user();

            // S� do usu�rio autenticado e filtrando a categoria
            $query = Establishment::where('user_id', $user->id)
                ->where('category', $category);

            // Se quiser pagina��o, pode ajustar o n�mero de itens por p�gina aqui
            $establishments = $query->paginate(10);

            return response()->json([
                'message' => 'Estabelecimentos do usu�rio listados por categoria com sucesso.',
                'establishments' => $establishments,
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erro ao listar estabelecimentos do usu�rio por categoria: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar seus estabelecimentos por categoria.'], 500);
        }
    }


}
