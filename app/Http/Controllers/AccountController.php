<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class AccountController extends Controller
{
    public function __construct()
    {
        $this->middleware(['api', 'auth:api']);
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'user_name' => 'nullable|string|max:255|unique:users,user_name,' . $user->id,
            'cpf' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'postal_code' => 'nullable|string|max:12',
            'birthdate' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'occupation' => 'nullable|string|max:255',
            'about' => 'nullable|string|max:3000',
            'avatar' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:4096',
        ], [
            'user_name.unique' => 'Este nome de usuário já está em uso.',
            'uf.size' => 'A UF deve ter exatamente 2 caracteres.',
            'birthdate.date' => 'A data de nascimento é inválida.',
            'gender.in' => 'O gênero informado é inválido.',
            'avatar.image' => 'O arquivo selecionado para o avatar não é uma imagem válida.',
            'avatar.mimes' => 'O avatar deve estar em JPG, PNG ou WEBP.',
            'avatar.max' => 'O avatar deve ter no máximo 4 MB.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Existem dados inválidos no formulário.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $validated = $validator->validated();
            unset($validated['avatar']);

            foreach ($validated as $field => $value) {
                $user->{$field} = $value === '' ? null : $value;
            }

            if ($request->hasFile('avatar')) {
                $avatar = $request->file('avatar');
                if (!$avatar->isValid()) {
                    throw new \RuntimeException('O upload do avatar não foi concluído corretamente.');
                }

                $extension = strtolower($avatar->getClientOriginalExtension() ?: 'jpg');
                $filename = Str::uuid() . '.' . $extension;
                $path = $avatar->storeAs("uploads/user/{$user->id}/avatar", $filename, 'public');

                if (!$path) {
                    throw new \RuntimeException('Não foi possível gravar o avatar no armazenamento.');
                }

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
            DB::commit();

            return response()->json([
                'message' => 'Perfil atualizado com sucesso.',
                'user' => $user->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Account.updateProfile', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            $message = str_contains($e->getMessage(), 'avatar')
                ? $e->getMessage()
                : 'Não foi possível salvar as alterações do perfil. Tente novamente.';

            return response()->json([
                'message' => $message,
                'error' => $message,
            ], 500);
        }
    }

    public function requestEmailChange(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
        ], [
            'email.required' => 'Informe o novo e-mail.',
            'email.email' => 'Informe um endereço de e-mail válido.',
            'email.unique' => 'Este e-mail já está sendo utilizado por outra conta.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Não foi possível iniciar a alteração do e-mail.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $newEmail = strtolower(trim($validator->validated()['email']));

        if (strtolower((string) $user->email) === $newEmail) {
            return response()->json([
                'message' => 'Este já é o e-mail atual da sua conta.',
            ], 422);
        }

        $code = (string) random_int(100000, 999999);
        $extra = $this->extraInfo($user);
        $extra['email_change'] = [
            'email' => $newEmail,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
            'requested_at' => now()->toIso8601String(),
        ];

        try {
            $user->extra_info = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $user->save();

            Mail::raw(
                "Seu código de confirmação para alterar o e-mail é: {$code}\n\nEste código expira em 15 minutos. Se você não solicitou esta alteração, ignore esta mensagem.",
                function ($message) use ($newEmail) {
                    $message->to($newEmail)->subject('Confirmação de alteração de e-mail');
                }
            );

            return response()->json([
                'message' => 'Enviamos um código de 6 dígitos para o novo e-mail. Confirme o código para concluir a alteração.',
                'pending_email' => $newEmail,
            ]);
        } catch (\Throwable $e) {
            Log::error('Account.requestEmailChange', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível enviar o código de confirmação para o novo e-mail.',
                'error' => 'Falha no envio do código de confirmação.',
            ], 500);
        }
    }

    public function confirmEmailChange(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'code' => 'required|digits:6',
        ], [
            'code.required' => 'Informe o código de confirmação.',
            'code.digits' => 'O código deve conter 6 dígitos.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Código inválido.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $extra = $this->extraInfo($user);
        $pending = $extra['email_change'] ?? null;

        if (!is_array($pending) || empty($pending['email']) || empty($pending['code_hash']) || empty($pending['expires_at'])) {
            return response()->json([
                'message' => 'Não existe uma alteração de e-mail pendente. Solicite um novo código.',
            ], 422);
        }

        if (now()->greaterThan(\Carbon\Carbon::parse($pending['expires_at']))) {
            unset($extra['email_change']);
            $user->extra_info = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $user->save();

            return response()->json([
                'message' => 'O código expirou. Solicite um novo código de confirmação.',
            ], 422);
        }

        if (!hash_equals($pending['code_hash'], hash('sha256', $validator->validated()['code']))) {
            return response()->json([
                'message' => 'Código de confirmação incorreto.',
            ], 422);
        }

        $newEmail = strtolower(trim($pending['email']));
        if (User::where('email', $newEmail)->where('id', '!=', $user->id)->exists()) {
            return response()->json([
                'message' => 'Este e-mail passou a ser utilizado por outra conta. Informe outro e-mail.',
            ], 422);
        }

        try {
            $user->email = $newEmail;
            $user->email_verified_at = now();
            unset($extra['email_change']);
            $user->extra_info = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $user->save();

            return response()->json([
                'message' => 'E-mail alterado e confirmado com sucesso.',
                'user' => $user->fresh(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Account.confirmEmailChange', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível concluir a alteração do e-mail.',
            ], 500);
        }
    }

    private function extraInfo(User $user): array
    {
        $raw = $user->extra_info;

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
