<?php

namespace App\Services;

use App\Mail\VerificationCodeMail;
use App\Mail\WelcomeMail;
use App\Models\File;
use App\Models\Interaction;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserController extends ApiController
{
    public function list(Request $request)
    {
        $this->requirePermission('user_list');
        $data = $request->validate(['per_page' => 'nullable|integer|min:1|max:100']);

        return response()->json([
            'users' => User::query()->with('profile')->orderBy('first_name')->paginate($data['per_page'] ?? 20),
            'profiles' => Profile::all(),
        ]);
    }

    public function show($id)
    {
        $actor = Auth::user();
        $user = User::with(['profile', 'files'])->findOrFail($id);

        if ((int) $actor->id !== (int) $user->id && ! $this->hasPermission($actor, 'user_show')) {
            return response()->json(['error' => 'Sem permissão para visualizar este usuário.'], 403);
        }

        Interaction::registerView($user, $actor);
        return response()->json(['user' => $user]);
    }

    public function store(Request $request)
    {
        $this->requirePermission('user_create');
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'required|email|max:255|unique:users,email',
            'profile_id' => 'nullable|integer|exists:profiles,id',
        ]);

        $this->assertCanAssignProfile($data['profile_id'] ?? null);

        $temporaryPassword = Str::random(14) . 'Aa1!';
        $verificationCode = strtoupper(Str::random(6));

        $user = User::create([
            'first_name' => trim($data['first_name']),
            'last_name' => $data['last_name'] ?? null,
            'email' => strtolower(trim($data['email'])),
            'user_name' => $this->uniqueUsername($data['first_name']),
            'password' => Hash::make($temporaryPassword),
            'verification_code' => Hash::make($verificationCode),
            'verification_code_expires_at' => now()->addDay(),
            'profile_id' => $data['profile_id'] ?? null,
        ]);

        Interaction::register('create', $user, Auth::user());
        Mail::to($user->email)->send(new WelcomeMail($verificationCode, $user, $temporaryPassword));

        return response()->json(['message' => 'Usuário criado com sucesso.', 'user' => $user], 201);
    }

    public function update(Request $request, User $user)
    {
        $actor = Auth::user();
        $editingOther = (int) $actor->id !== (int) $user->id;

        if ($editingOther && ! $this->hasPermission($actor, 'user_edit')) {
            return response()->json(['error' => 'Sem permissão para atualizar usuário.'], 403);
        }

        $rules = [
            'first_name' => 'sometimes|string|max:100',
            'last_name' => 'sometimes|nullable|string|max:100',
            'user_name' => 'sometimes|string|max:100|unique:users,user_name,' . $user->id,
            'email' => 'sometimes|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'sometimes|nullable|string|max:30',
            'city' => 'sometimes|nullable|string|max:120',
            'uf' => 'sometimes|nullable|string|size:2',
            'postal_code' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:500',
            'about' => 'sometimes|nullable|string|max:5000',
            'avatar' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'background' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:8192',
        ];

        if ($editingOther && $this->hasPermission($actor, 'user_config')) {
            $rules['profile_id'] = 'sometimes|nullable|integer|exists:profiles,id';
        }

        $data = $request->validate($rules);
        $this->assertCanAssignProfile($data['profile_id'] ?? null);

        $changes = [];
        $emailVerificationCode = null;

        DB::transaction(function () use ($request, $user, $actor, $data, &$changes, &$emailVerificationCode) {
            foreach (collect($data)->except(['avatar', 'background'])->all() as $key => $value) {
                if ($key === 'uf' && $value) {
                    $value = strtoupper($value);
                }
                if ($key === 'email' && $value) {
                    $value = strtolower(trim($value));
                }
                if ($user->{$key} != $value) {
                    $changes[$key] = ['from' => $user->{$key}, 'to' => $value];
                    $user->{$key} = $value;
                    if ($key === 'email') {
                        $emailVerificationCode = strtoupper(Str::random(6));
                        $user->email_verified_at = null;
                        $user->verification_code = Hash::make($emailVerificationCode);
                        $user->verification_code_expires_at = now()->addMinutes(30);
                    }
                }
            }

            if ($request->hasFile('avatar')) {
                foreach ($user->files()->where('type', 'avatar')->get() as $old) {
                    if ($old->path) {
                        Storage::disk('public')->delete($old->path);
                    }
                    $old->delete();
                }
                $file = File::storeOne($request->file('avatar'), 'user', $user->id, 'avatar', null, $actor->id);
                $changes['avatar'] = ['from' => $user->avatar, 'to' => $file->public_url];
                $user->avatar = $file->public_url;
            }

            if ($request->hasFile('background')) {
                foreach ($user->files()->where('type', 'background')->get() as $old) {
                    if ($old->path) {
                        Storage::disk('public')->delete($old->path);
                    }
                    $old->delete();
                }
                $file = File::storeOne($request->file('background'), 'user', $user->id, 'background', null, $actor->id);
                $changes['background'] = ['from' => $user->background, 'to' => $file->public_url];
                $user->background = $file->public_url;
            }

            $user->save();
            if ($changes !== []) {
                Interaction::registerUpdate($user, $actor, $changes);
            }
        });

        if ($emailVerificationCode !== null) {
            Mail::to($user->email)->send(new VerificationCodeMail($emailVerificationCode, $user));
        }

        return response()->json(['message' => 'Usuário atualizado com sucesso.', 'user' => $user->fresh()->load(['profile', 'files'])]);
    }

    public function destroy($id)
    {
        $this->requirePermission('user_delete');
        abort_if((int) Auth::id() === (int) $id, 403, 'Você não pode se auto-excluir.');
        $user = User::findOrFail($id);
        abort_if($user->hasProfile('Administrador') && ! Auth::user()->hasProfile('Administrador'), 403, 'Você não pode excluir um administrador.');

        Interaction::register('delete', $user, Auth::user());
        $user->delete();
        return response()->json(['message' => 'Usuário excluído com sucesso.']);
    }

    public function search(Request $request)
    {
        $this->assertDirectoryAccess();
        $data = $request->validate([
            'q' => 'required|string|min:2|max:255',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        $q = trim($data['q']);

        $users = User::query()
            ->select(['id', 'first_name', 'last_name', 'user_name', 'email', 'phone', 'city', 'uf', 'avatar', 'profile_id'])
            ->where(function ($query) use ($q) {
                if (ctype_digit($q)) {
                    $query->orWhere('id', (int) $q);
                }
                $query->orWhere('email', 'like', '%' . $q . '%')
                    ->orWhere('phone', 'like', '%' . preg_replace('/\D/', '', $q) . '%')
                    ->orWhere('first_name', 'like', '%' . $q . '%')
                    ->orWhere('last_name', 'like', '%' . $q . '%')
                    ->orWhere('user_name', 'like', '%' . $q . '%');
            })
            ->with('profile')
            ->orderBy('first_name')
            ->paginate($data['per_page'] ?? 15);

        return response()->json(['message' => 'Busca concluída.', 'results' => $users]);
    }

    public function view(string $userName)
    {
        $user = User::query()
            ->select(['id', 'first_name', 'last_name', 'user_name', 'avatar', 'background', 'city', 'uf', 'about', 'profile_id'])
            ->with([
                'profile:id,name',
                'files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active'),
                'employer.establishment.files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active'),
            ])
            ->where('user_name', $userName)
            ->firstOrFail();

        Interaction::registerView($user, Auth::user());
        return response()->json([
            'user' => $user,
            'employer' => $user->employer,
            'establishment' => $user->employer?->establishment,
            'items' => $user->employer?->establishment?->items ?? [],
        ]);
    }

    public function findForEmployer(Request $request)
    {
        $this->assertDirectoryAccess();
        $data = $request->validate([
            'first_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'cpf' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'user_name' => 'nullable|string|max:100',
        ]);
        abort_if(collect($data)->filter()->isEmpty(), 422, 'Informe ao menos um critério.');

        $query = User::query()->select(['id', 'first_name', 'last_name', 'user_name', 'email', 'phone', 'city', 'uf', 'avatar']);
        foreach (['first_name', 'email', 'phone', 'user_name'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, 'like', '%' . $data[$field] . '%');
            }
        }
        if (! empty($data['cpf'])) {
            $query->where('cpf', preg_replace('/\D/', '', $data['cpf']));
        }

        return response()->json(['message' => 'Busca realizada com sucesso.', 'users' => $query->limit(50)->get()]);
    }

    public function findForOrder(Request $request)
    {
        $this->assertDirectoryAccess();
        $data = $request->validate(['q' => 'required|string|min:2|max:255']);
        $q = trim($data['q']);

        $users = User::query()
            ->select(['id', 'first_name', 'last_name', 'user_name', 'email', 'phone', 'city', 'uf', 'avatar'])
            ->where(function ($query) use ($q) {
                if (ctype_digit($q)) {
                    $query->orWhere('id', (int) $q);
                }
                $query->orWhere('email', 'like', '%' . $q . '%')
                    ->orWhere('phone', 'like', '%' . preg_replace('/\D/', '', $q) . '%')
                    ->orWhere('first_name', 'like', '%' . $q . '%')
                    ->orWhere('last_name', 'like', '%' . $q . '%')
                    ->orWhere('user_name', 'like', '%' . $q . '%');
            })
            ->orderBy('first_name')
            ->limit(50)
            ->get();

        return response()->json(['message' => 'Busca realizada com sucesso.', 'users' => $users]);
    }

    private function assertDirectoryAccess(): void
    {
        $user = Auth::user();
        $hasBusinessContext = $user && ($user->establishments()->exists() || $user->employer()->exists());
        abort_unless($user && ($user->hasProfile('Administrador') || $user->hasPermission('user_list') || $hasBusinessContext), 403, 'Sem permissão para pesquisar usuários.');
    }

    private function requirePermission(string $permission): void
    {
        abort_unless($this->hasPermission(Auth::user(), $permission), 403, 'Sem permissão.');
    }

    private function hasPermission(?User $user, string $permission): bool
    {
        return $user && ($user->hasProfile('Administrador') || $user->hasPermission($permission));
    }

    private function assertCanAssignProfile(?int $profileId): void
    {
        if (! $profileId) {
            return;
        }

        $isAdminProfile = Profile::query()
            ->whereKey($profileId)
            ->where('name', 'Administrador')
            ->exists();

        if ($isAdminProfile) {
            abort_unless(Auth::user()?->hasProfile('Administrador'), 403, 'Somente um administrador pode atribuir o perfil Administrador.');
        }
    }

    private function uniqueUsername(string $name): string
    {
        $base = Str::slug($name) ?: 'user';
        do {
            $username = $base . '-' . strtolower(Str::random(6));
        } while (User::where('user_name', $username)->exists());
        return $username;
    }
}
