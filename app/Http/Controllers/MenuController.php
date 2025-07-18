<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\Interaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class MenuController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'establishment_id.required'       => 'O ID do estabelecimento é obrigatório.',
            'establishment_id.integer'        => 'O ID do estabelecimento deve ser um número inteiro.',
            'establishment_id.exists'         => 'O estabelecimento informado não existe.',

            'name.required'                  => 'O nome do menu é obrigatório.',
            'name.string'                    => 'O nome do menu deve ser uma string válida.',
            'name.max'                       => 'O nome do menu deve ter no máximo 255 caracteres.',

            'description.string'             => 'A descrição deve ser uma string válida.',

            'valid_from.date'                => 'A data de início de validade deve ser uma data válida.',
            'valid_to.date'                  => 'A data de término de validade deve ser uma data válida.',
            'valid_to.after'                 => 'A data de término de validade deve ser posterior à data de início.',

            'price_modifier_percent.numeric' => 'O modificador de preço deve ser um número.',
            'price_modifier_percent.min'     => 'O modificador de preço não pode ser negativo.',
            'price_modifier_percent.max'     => 'O modificador de preço não pode exceder 100.',

            'is_active.boolean'              => 'O campo ativo deve ser verdadeiro ou falso.',

            'cover_image.image'              => 'A imagem de capa deve ser um arquivo de imagem válido.',
            'cover_image.mimes'              => 'A imagem de capa deve ser do tipo jpeg, png ou jpg.',
            'cover_image.max'                => 'A imagem de capa não pode exceder 2MB.',
        ];
    }

    public function store(Request $request)
    {
        try {
            if (!Auth::check()) {
                Log::warning('Tentativa de criar menu sem autenticação.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            Log::info('Iniciando criação de menu.', ['user_id' => $user->id]);

            if (!$user->hasPermission('menu_store')) {
                Log::warning('Usuário sem permissão tentou criar menu.', ['user_id' => $user->id]);
                return response()->json(['error' => 'Você não tem permissão para criar menus.'], 403);
            }

            $validated = $request->validate([
                'establishment_id'       => 'required|integer|exists:establishments,id',
                'name'                   => 'required|string|max:255',
                'description'            => 'nullable|string',
                'valid_from'             => 'nullable|date',
                'valid_to'               => 'nullable|date|after:valid_from',
                'price_modifier_percent' => 'nullable|numeric|min:0|max:100',
                'is_active'              => 'sometimes|boolean',
                'cover_image'            => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            ], $this->getValidationMessages());

            $menu = new Menu();
            $menu->fill($validated);
            $slug = Str::slug($validated['name']);
            $count = Menu::where('slug', $slug)->count();
            if ($count > 0) {
                $slug .= '-' . ($count + 1);
            }
            $menu->slug = $slug;
            $menu->save();

            if ($request->hasFile('cover_image')) {
                $destination = public_path('images');
                $filename = uniqid('menu_cover_') . '.' . $request->file('cover_image')->getClientOriginalExtension();
                $request->file('cover_image')->move($destination, $filename);
                $img = Image::make($destination . '/' . $filename)->fit(800, 600);
                $img->save();
                $menu->cover_image = 'images/' . $filename;
                $menu->save();
            }

            $interaction = new Interaction();
            $interaction->user_id          = $user->id;
            $interaction->interaction_type = 'Create';
            $interaction->entity_type      = 'menu';
            $interaction->entity_id        = $menu->id;
            $interaction->content          = "O usuário {$user->first_name} criou o menu {$menu->name}.";
            $interaction->save();

            return response()->json(['message' => 'Menu criado com sucesso.', 'menu' => $menu], 201);
        } catch (ValidationException $e) {
            Log::error('Erro de validação ao criar menu.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao criar menu: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao criar o menu.'], 500);
        }
    }
}
