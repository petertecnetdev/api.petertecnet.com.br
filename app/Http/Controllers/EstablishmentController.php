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
    protected function getValidationMessages()
    {
        return [
            'name.required' => 'O nome do estabelecimento é obrigatório.',
            'name.string' => 'O nome deve ser uma string válida.',
            'name.max' => 'O nome deve ter no máximo 255 caracteres.',
            'email.email' => 'O email fornecido não é válido.',
            'email.max' => 'O email deve ter no máximo 255 caracteres.',
            'phone.string' => 'O telefone deve ser uma string válida.',
            'phone.max' => 'O telefone deve ter no máximo 20 caracteres.',
            'description.string' => 'A descrição deve ser uma string válida.',
            'description.max' => 'A descrição deve ter no máximo 2500 caracteres.',
            'address.string' => 'O endereço deve ser uma string válida.',
            'address.max' => 'O endereço deve ter no máximo 255 caracteres.',
            'city.string' => 'A cidade deve ser uma string válida.',
            'city.max' => 'A cidade deve ter no máximo 100 caracteres.',
            'cep.string' => 'O CEP deve ser uma string válida.',
            'cep.max' => 'O CEP deve ter no máximo 10 caracteres.',
            'website_url.url' => 'O website deve ser um URL válido.',
            'location.string' => 'A localização deve ser uma string válida.',
            'instagram_url.url' => 'O link do instagram deve ser um URL válido.',
            'logo.required' => 'A logo é obrigatória.',
            'logo.image' => 'A logo deve ser uma imagem válida.',
            'logo.max' => 'O nome do arquivo da logo deve ter no máximo 1255 caracteres.',
            'background.required' => 'A imagem de fundo é obrigatória.',
            'background.image' => 'A imagem de fundo deve ser uma imagem válida.',
        ];
    }

    public function store(Request $request)
    {
        try {
            if (!Auth::check()) {
                Log::warning('Tentativa de cadastro sem autenticação.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            Log::info('Usuário autenticado:', ['user_id' => $user->id, 'email' => $user->email]);

            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:20',
                'description' => 'nullable|string|max:2500',
                'address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'cep' => 'nullable|string|max:10',
                'website_url' => 'nullable|string',
                'location' => 'nullable|string',
                'instagram_url' => 'nullable|string',
                'logo' => 'required|image',
                'background' => 'required|image',
            ], $this->getValidationMessages());

            Log::info('Dados validados para a criação do estabelecimento.', $validatedData);

            $establishment = new Establishment();
            $establishment->fill($validatedData);
            $establishment->user_id = $user->id;
            $establishment->save();

            if ($request->hasFile('logo')) {
                Log::info('Imagem de logo fornecida, processando...');
                $destinationPath = public_path('images');
                $imageName = uniqid('logo_') . '.' . $request->file('logo')->getClientOriginalExtension();
                $request->file('logo')->move($destinationPath, $imageName);
                $image = Image::make($destinationPath . '/' . $imageName);
                $image->fit(150, 150);
                $image->save();
                $establishment->logo = 'images/' . $imageName;
                $establishment->save();
            }

            if ($request->hasFile('background')) {
                Log::info('Imagem de fundo fornecida, processando...');
                $destinationPath = public_path('images');
                $imageName = uniqid('background_') . '.' . $request->file('background')->getClientOriginalExtension();
                $request->file('background')->move($destinationPath, $imageName);
                $image = Image::make($destinationPath . '/' . $imageName);
                $image->fit(1920, 600);
                $image->save();
                $establishment->background = 'images/' . $imageName;
                $establishment->save();
            }

            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'Create';
            $interaction->entity_id = $establishment->id;
            $interaction->content = "O usuário " . $user->first_name . " criou o estabelecimento " . $establishment->name . ".";
            $interaction->entity_type = 'establishment';
            $interaction->save();

            return response()->json(['message' => 'Estabelecimento cadastrado com sucesso.', 'establishment' => $establishment], 201);

        } catch (ValidationException $e) {
            Log::error('Erro de validação ao cadastrar o estabelecimento.', ['errors' => $e->errors()]);
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
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();

            try {
                $establishment = Establishment::findOrFail($id);
            } catch (ModelNotFoundException $e) {
                return response()->json(['error' => 'Estabelecimento não encontrado com o ID fornecido.'], 404);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:20',
                'description' => 'nullable|string|max:2500',
                'address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'cep' => 'nullable|string|max:10',
                'website_url' => 'nullable|string',
                'location' => 'nullable|string',
                'instagram_url' => 'nullable|string',
                'logo' => 'nullable|image|max:1255',
                'background' => 'nullable|image|max:1255',
            ], $this->getValidationMessages());

            $establishment->fill($validatedData);
            $establishment->updated_by = $user->id ?? null;

            if ($request->hasFile('logo')) {
                $destinationPath = public_path('images');
                $imageName = uniqid('logo_') . '.' . $request->file('logo')->getClientOriginalExtension();
                $request->file('logo')->move($destinationPath, $imageName);
                $image = Image::make($destinationPath . '/' . $imageName);
                $image->fit(150, 150);
                $image->save();
                $establishment->logo = 'images/' . $imageName;
            }

            if ($request->hasFile('background')) {
                $destinationPath = public_path('images');
                $imageName = uniqid('background_') . '.' . $request->file('background')->getClientOriginalExtension();
                $request->file('background')->move($destinationPath, $imageName);
                $image = Image::make($destinationPath . '/' . $imageName);
                $image->fit(1920, 600);
                $image->save();
                $establishment->background = 'images/' . $imageName;
            }

            if ($request->has('name')) {
                $slug = Str::slug($request->input('name'));
                $count = Establishment::where('slug', $slug)->where('id', '!=', $establishment->id)->count();
                if ($count > 0) {
                    $slug = $slug . '-' . ($count + 1);
                }
                $establishment->slug = $slug;
            }

            $establishment->save();

            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'Update';
            $interaction->entity_id = $establishment->id;
            $interaction->content = "O usuário " . $user->first_name . " atualizou o estabelecimento " . $establishment->name . ".";
            $interaction->entity_type = 'establishment';
            $interaction->save();

            return response()->json(['message' => 'Estabelecimento atualizado com sucesso.', 'establishment' => $establishment], 200);

        } catch (ValidationException $e) {
            Log::error('Erro de validação ao atualizar o estabelecimento.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar estabelecimento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao atualizar o estabelecimento.'], 500);
        }
    }

    public function destroy($id)
{
    try {
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        $user = Auth::user();

        if (!$user->hasPermission('establishment_destroy')) {
            return response()->json(['error' => 'Você não tem permissão para deletar estabelecimentos.'], 403);
        }

        $establishment = Establishment::findOrFail($id);

        $interaction = new Interaction();
        $interaction->user_id = $user->id;
        $interaction->interaction_type = 'Destroy';
        $interaction->entity_id = $establishment->id;
        $interaction->content = "O usuário " . $user->first_name . " deletou o estabelecimento " . $establishment->name . ".";
        $interaction->entity_type = 'establishment';
        $interaction->save();

        $establishment->delete();

        return response()->json(['message' => 'Estabelecimento excluído com sucesso.'], 200);

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json(['error' => 'Estabelecimento não encontrado.'], 404);

    } catch (\Exception $e) {
        Log::error('Erro ao excluir estabelecimento: ' . $e->getMessage());
        return response()->json(['error' => 'Ocorreu um erro ao excluir o estabelecimento.'], 500);
    }
}

public function listByUser(Request $request) 
{
    try {
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        $user = Auth::user();

        if (!$user->hasPermission('establishment_list')) {
            return response()->json(['error' => 'Você não tem permissão para listar seus estabelecimentos.'], 403);
        }

        $establishments = Establishment::where('user_id', $user->id)->paginate(10);

        return response()->json([
            'message' => 'Estabelecimentos listados com sucesso.',
            'establishments' => $establishments,
        ], 200);

    } catch (\Exception $e) {
        Log::error('Erro ao listar estabelecimentos do usuário: ' . $e->getMessage());
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

        $items = $establishment->items()->get();

        $otherEstablishments = Establishment::where('slug', '!=', $slug)
            ->inRandomOrder()
            ->limit(3)
            ->get();

        $otherEstablishmentDetails = $otherEstablishments->map(function ($other) {
            return [
                'name' => $other->name,
                'slug' => $other->slug,
                'logo' => $other->logo,
            ];
        });

        if ($user) {
            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'View';
            $interaction->entity_id = $establishment->id;
            $interaction->content = "O usuário " . $user->first_name . " acessou o estabelecimento.";
            $interaction->entity_type = 'establishment';
            $interaction->save();
        }

        return response()->json([
            'message' => 'Estabelecimento encontrado com sucesso.',
            'establishment' => $establishment,
            'items' => $items,
            'owner' => $establishment->user,
            'otherEstablishments' => $otherEstablishmentDetails
        ], 200);

    } catch (\Exception $e) {
        Log::error('Erro ao buscar o estabelecimento: ' . $e->getMessage());
        return response()->json(['error' => 'Ocorreu um erro ao buscar o estabelecimento.'], 500);
    }
}
public function show($id)
{
    try {
        $user = Auth::user();

        $establishment = Establishment::find($id);

        if (!$establishment) {
            return response()->json(['error' => 'Estabelecimento não encontrado.'], 404);
        }

        $interaction = new Interaction();
        $interaction->user_id = $user ? $user->id : null;
        $interaction->interaction_type = 'View';
        $interaction->entity_id = $establishment->id;
        $interaction->content = "O usuário " . ($user ? $user->first_name : 'anônimo') . " acessou o estabelecimento.";
        $interaction->entity_type = 'establishment';
        $interaction->save();

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



}
