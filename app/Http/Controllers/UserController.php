<?php

namespace App\Http\Controllers;

use App\Models\{
    User,
    Profile,
    Interaction,
    File
};
use App\Mail\WelcomeMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{
    Auth,
    Log,
    Validator,
    Mail,
    DB
};
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class UserController extends ApiController
{
    /* =======================================================
     | HELPERS
     ======================================================= */

    protected function validationMessages(): array
    {
        return [
            'first_name.required' => 'O campo primeiro nome é obrigatório.',
            'first_name.string' => 'O campo primeiro nome deve conter texto válido.',
            'first_name.max' => 'O campo primeiro nome pode ter no máximo 255 caracteres.',
            'last_name.string' => 'O campo sobrenome deve conter texto válido.',
            'last_name.max' => 'O campo sobrenome pode ter no máximo 255 caracteres.',
            'user_name.required' => 'O campo nome de usuário é obrigatório.',
            'user_name.string' => 'O campo nome de usuário deve conter texto válido.',
            'user_name.max' => 'O nome de usuário pode ter no máximo 255 caracteres.',
            'user_name.unique' => 'Este nome de usuário já está em uso.',
            'email.required' => 'O campo e-mail é obrigatório.',
            'email.email' => 'O e-mail informado é inválido.',
            'email.unique' => 'Este e-mail já está em uso.',
            'avatar.image' => 'O avatar deve ser uma imagem válida.',
            'avatar.max' => 'O avatar pode ter no máximo 4MB.',
            'q.required' => 'Você precisa informar algo para buscar.',
            'q.string' => 'O termo de busca deve ser texto.',
            'q.max' => 'O termo de busca pode ter no máximo 255 caracteres.',
        ];
    }

    protected function authUser(): User
    {
        if (!Auth::check()) {
            abort(401, 'Usuário não autenticado.');
        }

        return Auth::user();
    }

    /* =======================================================
     | CRUD
     ======================================================= */

    public function list()
    {
        try {
            $user = $this->authUser();

            if (!$user->hasPermission('user_list')) {
                return response()->json(['error' => 'Sem permissão para listar usuários.'], 403);
            }

            Interaction::register('list', $user, $user);

            return response()->json([
                'users' => User::with('profile')->paginate(20),
                'profiles' => Profile::all(),
            ]);

        } catch (\Throwable $e) {
            Log::error('User.list', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao listar usuários.'], 500);
        }
    }

    public function show($id)
    {
        try {
            $auth = $this->authUser();
            $user = User::with(['profile', 'files'])->findOrFail($id);

            Interaction::registerView($user, $auth);

            return response()->json(['user' => $user]);

        } catch (\Throwable $e) {
            Log::error('User.show', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao carregar usuário.'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
            ], $this->validationMessages());

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $password = Str::random(10);
            $verificationCode = Str::random(6);

            $username = Str::slug($request->first_name) . '-' . Str::random(4);
            while (User::where('user_name', $username)->exists()) {
                $username = Str::slug($request->first_name) . '-' . Str::random(4);
            }

            $user = User::create([
                'first_name' => $request->first_name,
                'email' => $request->email,
                'user_name' => $username,
                'password' => bcrypt($password),
                'verification_code' => $verificationCode,
            ]);

            Interaction::register('create', $user, $user);

            Mail::to($user->email)->send(new WelcomeMail($verificationCode, $user, $password));

            return response()->json([
                'message' => 'Usuário criado com sucesso.',
                'user' => $user,
            ], 201);

        } catch (\Throwable $e) {
            Log::error('User.store', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao criar usuário.'], 500);
        }
    }

    public function update(Request $request, User $user)
    {
        try {
            $current = $this->authUser();

            if ($current->id !== $user->id && !$current->hasPermission('user_edit')) {
                return response()->json(
                    ['error' => 'Sem permissão para atualizar usuário.'],
                    403,
                    [],
                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                );
            }

            $validator = Validator::make($request->all(), [
                'first_name' => 'nullable|string|max:255',
                'last_name' => 'nullable|string|max:255',
                'user_name' => 'nullable|string|max:255|unique:users,user_name,' . $user->id,
                'email' => 'nullable|email|unique:users,email,' . $user->id,
                'avatar' => 'nullable|image|max:4096',
            ], $this->validationMessages());

            if ($validator->fails()) {
                return response()->json(
                    ['errors' => $validator->errors()],
                    422,
                    [],
                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                );
            }

            DB::beginTransaction();

            $changes = [];

            foreach ($validator->validated() as $key => $value) {
                if ($key !== 'avatar' && $user->{$key} !== $value) {
                    $changes[$key] = [
                        'from' => $user->{$key},
                        'to' => $value,
                    ];
                    $user->{$key} = $value;
                }
            }

            if ($request->hasFile('avatar')) {
                $file = File::storeOne(
                    file: $request->file('avatar'),
                    entityName: 'user',
                    entityId: $user->id,
                    type: 'avatar',
                    appId: null,
                    createdBy: $current->id
                );

                $changes['avatar'] = [
                    'from' => $user->avatar,
                    'to' => $file->public_url,
                ];

                $user->avatar = $file->public_url;
            }

            $user->save();

            Interaction::registerUpdate($user, $current, $changes);

            DB::commit();

            $fresh = $user->refresh()->toArray();
            $fresh = $this->sanitizeUtf8Recursive($fresh);

            return response()->json(
                [
                    'message' => 'Usuário atualizado com sucesso.',
                    'user' => $fresh,
                ],
                200,
                [],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('User.update', ['error' => $e->getMessage()]);

            return response()->json(
                ['error' => 'Erro ao atualizar usuário.'],
                500,
                [],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
        }
    }

    private function sanitizeUtf8Recursive($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $kk = is_string($k) ? $this->sanitizeUtf8String($k) : $k;
                $out[$kk] = $this->sanitizeUtf8Recursive($v);
            }
            return $out;
        }

        if (is_string($value)) {
            return $this->sanitizeUtf8String($value);
        }

        return $value;
    }

    private function sanitizeUtf8String(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        }

        $clean = iconv('UTF-8', 'UTF-8//IGNORE', $value);
        return $clean !== false ? $clean : $value;
    }
    public function destroy($id)
    {
        try {
            $current = $this->authUser();

            if (!$current->hasPermission('user_delete')) {
                return response()->json(['error' => 'Sem permissão para excluir usuário.'], 403);
            }

            if ($current->id == $id) {
                return response()->json(['error' => 'Você não pode se auto-excluir.'], 403);
            }

            $user = User::findOrFail($id);

            Interaction::register('delete', $user, $current);

            $user->delete();

            return response()->json(['message' => 'Usuário excluído com sucesso.']);

        } catch (\Throwable $e) {
            Log::error('User.destroy', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao excluir usuário.'], 500);
        }
    }

    /* =======================================================
     | SEARCH
     ======================================================= */

    public function search(Request $request)
    {
        try {
            $auth = $this->authUser();

            $request->validate([
                'q' => 'required|string|max:255',
            ], $this->validationMessages());

            $q = $request->q;

            $users = User::where(function ($query) use ($q) {
                if (ctype_digit($q)) {
                    $query->orWhere('id', (int) $q);
                }

                $query->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('cpf', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%");
            })
                ->with('profile')
                ->orderBy('first_name')
                ->paginate(15);

            Interaction::register('search', $auth, $auth, ['query' => $q]);

            return response()->json([
                'message' => 'Busca concluída.',
                'results' => $users,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Throwable $e) {
            Log::error('User.search', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao buscar usuários.'], 500);
        }
    }

    /* =======================================================
     | VIEW (PROFILE + CONTEXTO)
     ======================================================= */

    public function view(string $userName)
    {
        try {
            $auth = Auth::user();

            $user = User::with([
                'profile',
                'files',
                'employer.establishment.items.files',
                'employer.establishment.files',
            ])->where('user_name', $userName)->first();

            if (!$user) {
                return response()->json([
                    'error' => 'Usuário não encontrado.',
                ], 404);
            }

            Interaction::registerView($user, $auth);

            return response()->json([
                'user' => $user,
                'employer' => $user->employer,
                'establishment' => $user->employer?->establishment,
                'items' => $user->employer?->establishment?->items ?? [],
            ]);

        } catch (\Throwable $e) {
            Log::error('User.view', ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Erro ao carregar perfil.',
            ], 500);
        }
    }

    /* =======================================================
     | FIND FOR EMPLOYER
     ======================================================= */

    public function findForEmployer(Request $request)
    {
        try {
            $auth = $this->authUser();

            $validated = $request->validate([
                'first_name' => 'nullable|string|max:255',
                'email' => 'nullable|string|max:255',
                'cpf' => 'nullable|string|max:20',
                'phone' => 'nullable|string|max:20',
                'user_name' => 'nullable|string|max:255',
            ]);

            if (collect($validated)->filter()->isEmpty()) {
                return response()->json(['error' => 'Informe ao menos um critério.'], 422);
            }

            $users = User::with([
                'profile',
                'avatarFile',
                'employer.establishment.files',
            ])
                ->when($validated['first_name'] ?? null, fn($q, $v) => $q->where('first_name', 'like', "%{$v}%"))
                ->when($validated['email'] ?? null, fn($q, $v) => $q->where('email', 'like', "%{$v}%"))
                ->when($validated['cpf'] ?? null, fn($q, $v) => $q->where('cpf', 'like', '%' . preg_replace('/\D/', '', $v) . '%'))
                ->when($validated['phone'] ?? null, fn($q, $v) => $q->where('phone', 'like', '%' . preg_replace('/\D/', '', $v) . '%'))
                ->when($validated['user_name'] ?? null, fn($q, $v) => $q->where('user_name', 'like', "%{$v}%"))
                ->orderBy('first_name')
                ->get();

            Interaction::register('find_for_employer', $auth, $auth, $validated);

            return response()->json([
                'message' => 'Busca realizada com sucesso.',
                'count' => $users->count(),
                'users' => $users,
            ]);

        } catch (\Throwable $e) {
            Log::error('User.findForEmployer', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao buscar usuário.'], 500);
        }
    }

    public function findForOrder(Request $request)
    {
        try {
            $auth = $this->authUser();

            $validated = $request->validate([
                'q' => 'required|string|max:255',
            ], [
                'q.required' => 'Informe um termo para buscar o cliente.',
                'q.string' => 'O termo de busca deve ser texto.',
                'q.max' => 'O termo de busca pode ter no máximo 255 caracteres.',
            ]);

            $q = $validated['q'];

            $users = User::query()
                ->where(function ($query) use ($q) {
                    if (ctype_digit($q)) {
                        $query->orWhere('id', (int) $q);
                    }

                    $query->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('cpf', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('user_name', 'like', "%{$q}%");
                })
                ->select([
                    'id',
                    'first_name',
                    'last_name',
                    'user_name',
                    'email',
                    'phone',
                    'cpf',
                    'avatar',
                ])
                ->orderBy('first_name')
                ->limit(20)
                ->get();

            Interaction::register('find_for_order', $auth, $auth, [
                'query' => $q,
                'results' => $users->count(),
            ]);

            return response()->json([
                'message' => 'Busca de clientes realizada com sucesso.',
                'count' => $users->count(),
                'users' => $users,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Throwable $e) {
            Log::error('User.findForOrder', ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Erro ao buscar usuário para o pedido.',
            ], 500);
        }
    }

}
