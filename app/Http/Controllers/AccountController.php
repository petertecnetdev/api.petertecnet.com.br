<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AccountController extends Controller
{
    public function context(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'nullable|integer|exists:applications,id',
        ]);

        $appId = isset($data['app_id']) ? (int) $data['app_id'] : null;

        $user = User::query()
            ->with(['profile', 'employer'])
            ->findOrFail(Auth::id());

        $establishments = $user->establishments()
            ->when($appId, fn ($query) => $query->where('app_id', $appId))
            ->get();

        $applications = collect();

        if (Schema::hasTable('application_user')) {
            if ($appId) {
                $user->applications()->syncWithoutDetaching([
                    $appId => [
                        'status' => 'active',
                        'joined_at' => now(),
                    ],
                ]);
            }

            $applications = $user->applications()->get();
        }

        $employer = $user->employer;
        $user->unsetRelation('employer');

        return response()->json([
            'message' => 'Sessão carregada com sucesso.',
            'user' => $user,
            'is_employer' => (bool) $employer,
            'employer' => $employer,
            'establishments' => $establishments,
            'applications' => $applications,
            'app_id' => $appId,
        ]);
    }

    public function itemMetrics(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
            'establishment_id' => 'required|integer|min:1',
        ]);

        $establishment = Establishment::query()
            ->where('app_id', (int) $data['app_id'])
            ->where('id', (int) $data['establishment_id'])
            ->firstOrFail();

        abort_unless(
            (int) $establishment->user_id === (int) Auth::id()
                || (int) $establishment->created_by === (int) Auth::id()
                || Auth::user()?->hasProfile('Administrador'),
            403,
            'Acesso negado.'
        );

        $items = Item::query()
            ->where('app_id', (int) $data['app_id'])
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->withCount([
                'views as total_views' => fn ($query) => $query->where('interaction_type', 'view'),
            ])
            ->get(['id', 'slug', 'name'])
            ->map(fn (Item $item) => [
                'id' => $item->id,
                'slug' => $item->slug,
                'name' => $item->name,
                'total_views' => (int) $item->total_views,
            ]);

        return response()->json([
            'establishment_id' => $establishment->id,
            'items' => $items,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate([
            'first_name' => 'sometimes|nullable|string|max:100',
            'last_name' => 'sometimes|nullable|string|max:100',
            'user_name' => 'sometimes|nullable|string|max:100|unique:users,user_name,' . $user->id,
            'cpf' => 'sometimes|nullable|string|max:20',
            'phone' => 'sometimes|nullable|string|max:30',
            'address' => 'sometimes|nullable|string|max:500',
            'city' => 'sometimes|nullable|string|max:120',
            'uf' => 'sometimes|nullable|string|size:2',
            'postal_code' => 'sometimes|nullable|string|max:20',
            'birthdate' => 'sometimes|nullable|date|before_or_equal:today',
            'gender' => 'sometimes|nullable|in:male,female,other',
            'occupation' => 'sometimes|nullable|string|max:255',
            'about' => 'sometimes|nullable|string|max:3000',
            'avatar' => 'sometimes|nullable|image|mimes:jpeg,jpg,png,webp|max:4096',
        ]);

        DB::transaction(function () use ($request, $data, $user) {
            foreach (collect($data)->except('avatar')->all() as $field => $value) {
                $user->{$field} = $field === 'uf' && $value ? strtoupper($value) : $value;
            }

            if ($request->hasFile('avatar')) {
                $avatar = $request->file('avatar');
                $filename = Str::uuid() . '.' . strtolower($avatar->getClientOriginalExtension() ?: 'jpg');
                $path = $avatar->storeAs("uploads/user/{$user->id}/avatar", $filename, 'public');
                $oldAvatar = $user->avatar;
                $user->avatar = Storage::disk('public')->url($path);

                if ($oldAvatar && str_contains($oldAvatar, '/storage/')) {
                    $oldPath = ltrim(Str::after($oldAvatar, '/storage/'), '/');
                    if ($oldPath && $oldPath !== $path) {
                        Storage::disk('public')->delete($oldPath);
                    }
                }
            }

            $user->save();
        });

        return response()->json(['message' => 'Perfil atualizado com sucesso.', 'user' => $user->fresh()]);
    }

    public function requestEmailChange(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate([
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
        ]);
        $newEmail = strtolower(trim($data['email']));

        if (strtolower((string) $user->email) === $newEmail) {
            return response()->json(['message' => 'Este já é o e-mail atual da sua conta.'], 422);
        }

        $code = (string) random_int(100000, 999999);
        $extra = $this->extraInfo($user);
        $extra['email_change'] = [
            'email' => $newEmail,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
            'requested_at' => now()->toIso8601String(),
        ];

        $user->extra_info = $extra;
        $user->save();

        try {
            Mail::raw(
                "Seu código de confirmação para alterar o e-mail é: {$code}\n\nEste código expira em 15 minutos.",
                fn ($message) => $message->to($newEmail)->subject('Confirmação de alteração de e-mail')
            );
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar código de alteração de e-mail.', ['user_id' => $user->id, 'message' => $e->getMessage()]);
            unset($extra['email_change']);
            $user->extra_info = $extra;
            $user->save();
            return response()->json(['message' => 'Não foi possível enviar o código de confirmação.'], 503);
        }

        return response()->json([
            'message' => 'Enviamos um código de 6 dígitos para o novo e-mail.',
            'pending_email' => $newEmail,
        ]);
    }

    public function confirmEmailChange(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate(['code' => 'required|digits:6']);
        $extra = $this->extraInfo($user);
        $pending = $extra['email_change'] ?? null;

        if (! is_array($pending) || empty($pending['email']) || empty($pending['code_hash']) || empty($pending['expires_at'])) {
            return response()->json(['message' => 'Não existe uma alteração de e-mail pendente.'], 422);
        }
        if (now()->greaterThan(\Carbon\Carbon::parse($pending['expires_at']))) {
            unset($extra['email_change']);
            $user->extra_info = $extra;
            $user->save();
            return response()->json(['message' => 'O código expirou. Solicite um novo código.'], 422);
        }
        if (! Hash::check($data['code'], $pending['code_hash'])) {
            return response()->json(['message' => 'Código de confirmação incorreto.'], 422);
        }

        $newEmail = strtolower(trim($pending['email']));
        if (User::where('email', $newEmail)->where('id', '!=', $user->id)->exists()) {
            return response()->json(['message' => 'Este e-mail passou a ser utilizado por outra conta.'], 422);
        }

        $user->email = $newEmail;
        $user->email_verified_at = now();
        unset($extra['email_change']);
        $user->extra_info = $extra;
        $user->save();

        return response()->json(['message' => 'E-mail alterado e confirmado com sucesso.', 'user' => $user->fresh()]);
    }

    private function extraInfo(User $user): array
    {
        if (is_array($user->extra_info)) {
            return $user->extra_info;
        }
        if (is_string($user->extra_info) && $user->extra_info !== '') {
            $decoded = json_decode($user->extra_info, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
