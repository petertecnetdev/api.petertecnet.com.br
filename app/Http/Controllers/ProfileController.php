<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function list()
    {
        if (! $this->allowed('profile_view')) {
            return response()->json(['error' => 'Você não tem permissão para listar perfis.'], 403);
        }

        return response()->json(['profiles' => Profile::query()->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        if (! $this->allowed('profile_create')) {
            return response()->json(['error' => 'Você não tem permissão para criar perfis.'], 403);
        }

        $data = $request->validate($this->rules());
        $data['permissions'] = array_values(array_unique($data['permissions'] ?? []));

        $profile = Profile::create($data);

        return response()->json([
            'message' => 'Perfil criado com sucesso.',
            'profile' => $profile,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        if (! $this->allowed('profile_edit')) {
            return response()->json(['error' => 'Você não tem permissão para editar perfis.'], 403);
        }

        $profile = Profile::findOrFail($id);
        $data = $request->validate($this->rules($profile->id, false));

        if (array_key_exists('permissions', $data)) {
            $data['permissions'] = array_values(array_unique($data['permissions'] ?? []));
        }

        $profile->update($data);

        return response()->json([
            'message' => 'Perfil atualizado com sucesso.',
            'profile' => $profile->fresh(),
        ]);
    }

    public function show($id)
    {
        if (! $this->allowed('profile_view')) {
            return response()->json(['error' => 'Você não tem permissão para visualizar perfis.'], 403);
        }

        return response()->json(['profile' => Profile::findOrFail($id)]);
    }

    public function destroy($id)
    {
        if (! $this->allowed('profile_delete')) {
            return response()->json(['error' => 'Você não tem permissão para excluir perfis.'], 403);
        }

        $profile = Profile::findOrFail($id);

        if ($profile->name === 'Administrador') {
            return response()->json(['error' => 'O perfil Administrador não pode ser excluído.'], 409);
        }

        if ($profile->users()->exists()) {
            return response()->json([
                'error' => 'Este perfil está vinculado a usuários e não pode ser excluído.',
            ], 409);
        }

        $profile->delete();

        return response()->json(['message' => 'Perfil excluído com sucesso.']);
    }

    private function rules(?int $ignoreId = null, bool $creating = true): array
    {
        $permissionKeys = array_keys(config('permissions', []));
        $nameRules = [
            $creating ? 'required' : 'sometimes',
            'string',
            'max:100',
            Rule::unique('profiles', 'name')->ignore($ignoreId),
        ];

        return [
            'name' => $nameRules,
            'permissions' => [$creating ? 'nullable' : 'sometimes', 'array'],
            'permissions.*' => ['string', Rule::in($permissionKeys)],
        ];
    }

    private function allowed(string $permission): bool
    {
        $user = Auth::user();

        return $user && ($user->hasProfile('Administrador') || $user->hasPermission($permission));
    }
}
