<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\{User, Profile};
use App\Mail\WelcomeMail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\File;

class UserController extends Controller
{
    /**
     * Retorna mensagens de validação personalizadas.
     *
     * @return array
     */
    protected function getValidationMessages()
    {
        return [
            'first_name.required' => 'O campo :attribute é obrigatório.',
            'email.required'      => 'O campo e-mail é obrigatório.',
            'email.email'         => 'O e-mail deve ser um endereço de e-mail válido.',
            'email.unique'        => 'Este e-mail já está sendo utilizado por outro usuário.',
            'avatar.image'        => 'O arquivo deve ser uma imagem.',
            'avatar.mimes'        => 'O arquivo deve ter um formato de imagem válido (jpeg, png, jpg, gif).',
            'avatar.max'          => 'O tamanho máximo do arquivo é de 2MB.',
            // Outras mensagens de validação conforme necessário
        ];
    }

    /**
     * Obtém o usuário autenticado ou retorna erro.
     *
     * @return User|\Illuminate\Http\JsonResponse
     */
    protected function getAuthenticatedUser()
    {
        $user = Auth::user();
        if (!$user) {
            Log::error('Usuário não autenticado.');
            abort(response()->json(['error' => 'Usuário não autenticado.'], 401));
        }
        return $user;
    }

    /**
     * Atualiza os detalhes do usuário.
     *
     * @param Request $request
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $userId)
    {
        try {
            Log::info('Iniciando atualização do usuário.', ['userId' => $userId]);

            $currentUser = $this->getAuthenticatedUser();
            if ($currentUser->id != $userId && !$currentUser->hasPermission('user_edit')) {
                Log::error('Usuário não tem permissão para atualizar este usuário.');
                return response()->json(['error' => 'Você não tem permissão para atualizar este usuário.'], 403);
            }

            $validator = Validator::make($request->all(), [
                'first_name'               => 'nullable',
                'last_name'                => 'nullable',
                'email'                    => 'nullable|email',
                'verification_code'        => 'nullable',
                'avatar'                   => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'password'                 => 'nullable|min:6',
                'reset_password_code'      => 'nullable',
                'reset_password_expires_at'=> 'nullable|date',
                'remember_token'           => 'nullable',
                'profile_id'               => 'nullable|numeric',
                'cpf'                      => 'nullable',
                'address'                  => 'nullable',
                'phone'                    => 'nullable',
                'city'                     => 'nullable',
                'uf'                       => 'nullable',
                'postal_code'              => 'nullable',
                'birthdate'                => 'nullable|date',
                'gender'                   => 'nullable',
                'marital_status'           => 'nullable',
                'occupation'               => 'nullable',
                'about'                    => 'nullable',
                'favorite_artist'          => 'nullable',
                'favorite_genre'           => 'nullable',
                'payment_method'           => 'nullable',
                'newsletter_subscription'  => 'nullable|boolean',
                'ticket_purchases'         => 'nullable|numeric',
                'account_balance'          => 'nullable|numeric',
                'is_producer'              => 'nullable|boolean',
                'is_participant'           => 'nullable|boolean',
                'is_promoter'              => 'nullable|boolean',
                'is_barber'                => 'nullable|boolean',
                'is_barbershoper'          => 'nullable|boolean',
                'is_partner'               => 'nullable|boolean',
                'is_ticket_seller'         => 'nullable|boolean',
                'extra_info'               => 'nullable',
            ], $this->getValidationMessages());

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()->first()], 400);
            }

            $userToUpdate = User::findOrFail($userId);

            // Campos simples que podem ser atualizados diretamente
            $updatableFields = [
                'first_name', 'last_name', 'email', 'verification_code', 'reset_password_code',
                'reset_password_expires_at', 'remember_token', 'profile_id', 'cpf', 'address',
                'phone', 'city', 'uf', 'postal_code', 'birthdate', 'gender', 'marital_status',
                'occupation', 'about', 'favorite_artist', 'favorite_genre', 'payment_method',
                'newsletter_subscription', 'ticket_purchases', 'account_balance', 'is_producer',
                'is_participant', 'is_promoter', 'is_barber', 'is_barbershoper', 'is_partner',
                'is_ticket_seller', 'extra_info'
            ];

            foreach ($updatableFields as $field) {
                if ($request->has($field)) {
                    $userToUpdate->{$field} = $request->input($field);
                }
            }

            // Atualiza a senha se fornecida
            if ($request->has('password')) {
                $userToUpdate->password = bcrypt($request->input('password'));
            }

            // Processa avatar se fornecido
            if ($request->hasFile('avatar')) {
                Log::info('Avatar fornecido, processando...');
                $this->processAvatar($request->file('avatar'), $userToUpdate);
            }

            $userToUpdate->save();
            Log::info('Usuário atualizado com sucesso: ' . $userToUpdate->id);
            return response()->json(['message' => 'Usuário atualizado com sucesso.'], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Usuário não encontrado para atualização.', ['userId' => $userId]);
            return response()->json(['error' => 'Usuário não encontrado.'], 404);
        } catch (\Exception $e) {
            Log::error('Erro inesperado ao atualizar usuário.', [
                'userId' => $userId,
                'exception' => $e->getMessage()
            ]);
            return response()->json(['error' => 'Ocorreu um erro ao atualizar o usuário.'], 500);
        }
    }

    /**
     * Processa e armazena o avatar do usuário.
     *
     * @param \Illuminate\Http\UploadedFile $avatar
     * @param User $user
     * @return void
     */
    protected function processAvatar($avatar, $user)
    {
        $userId = $user->id;
        $extension = $avatar->getClientOriginalExtension();
        $avatarName = $userId . '-' . time() . '.' . $extension;
        $destinationPath = public_path('images');

        // Salva a imagem original
        $avatar->move($destinationPath, $avatarName);

        // Redimensiona a imagem para 512x512 mantendo a proporção
        $image = Image::make($destinationPath . '/' . $avatarName);
        $image->resize(512, 512, function ($constraint) {
            $constraint->aspectRatio();
        });
        $image->save();

        // Exclui avatar anterior se existir
        if ($user->avatar) {
            File::delete(public_path($user->avatar));
        }

        // Atualiza o caminho do avatar no banco de dados
        $user->avatar = 'images/' . $avatarName;
    }

    /**
     * Cria um novo usuário.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'first_name' => 'required',
                'email'      => 'required|email|unique:users',
            ], $this->getValidationMessages());

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()->first()], 400);
            }

            $verificationCode = Str::random(6);
            $password = Str::random(10);
            $username = Str::slug($request->input('first_name')) . '-' . Str::random(4);

            while (User::where('user_name', $username)->exists()) {
                $username = Str::slug($request->input('first_name')) . '-' . Str::random(4);
            }

            $newUser = User::create([
                'first_name'        => $request->input('first_name'),
                'email'             => $request->input('email'),
                'password'          => bcrypt($password),
                'user_name'         => $username,
                'verification_code' => $verificationCode,
            ]);

            Mail::to($newUser->email)->send(new WelcomeMail($verificationCode, $newUser, $password));
            return response()->json(['message' => 'Novo usuário cadastrado com sucesso.'], 201);

        } catch (\Exception $e) {
            Log::error('Erro ao cadastrar novo usuário: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao cadastrar o novo usuário.'], 500);
        }
    }

    /**
     * Lista todos os usuários e perfis.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function list()
    {
        try {
            $this->getAuthenticatedUser();

            $currentUser = Auth::user();
            if (!$currentUser->hasPermission('user_list')) {
                Log::error('Usuário não tem permissão para listar usuários.');
                return response()->json(['error' => 'Você não tem permissão para listar usuários.'], 403);
            }

            $users = User::all();
            $profiles = Profile::all();
            return response()->json(['users' => $users, 'profiles' => $profiles], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao listar usuários: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar usuários.'], 500);
        }
    }

    /**
     * Exibe os detalhes de um usuário pelo ID.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $this->getAuthenticatedUser();
            $userToShow = User::findOrFail($id);
            return response()->json(['user' => $userToShow], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao mostrar o perfil do usuário: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao mostrar o perfil do usuário.'], 500);
        }
    }

    /**
     * Exibe o perfil do usuário pelo user_name, incluindo produções ordenadas.
     *
     * @param string $userName
     * @return \Illuminate\Http\JsonResponse
     */
    public function view($userName)
    {
        try {
            $this->getAuthenticatedUser();

            $userToShow = User::with([
                'productions' => function ($query) {
                    $query->orderBy('created_at', 'desc');
                }
            ])->where('user_name', $userName)->first();

            if (!$userToShow) {
                return response()->json(['error' => 'Usuário não encontrado.'], 404);
            }

            return response()->json(['user' => $userToShow], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao mostrar o perfil do usuário: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao mostrar o perfil do usuário.'], 500);
        }
    }

    /**
     * Deleta um usuário.
     *
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($userId)
    {
        try {
            $currentUser = $this->getAuthenticatedUser();

            if (!$currentUser->hasPermission('user_delete')) {
                Log::error('Usuário não tem permissão para deletar este usuário.');
                return response()->json(['error' => 'Você não tem permissão para deletar este usuário.'], 403);
            }

            if ($currentUser->id == $userId) {
                Log::error('Tentativa de auto-deleção detectada.');
                return response()->json(['error' => 'Você não pode se auto-deletar.'], 403);
            }

            $userToDelete = User::findOrFail($userId);
            $userToDelete->delete();

            Log::info('Usuário deletado com sucesso: ' . $userId);
            return response()->json(['message' => 'Usuário deletado com sucesso.'], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao deletar o usuário: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao deletar o usuário.'], 500);
        }
    }

    
public function search(Request $request)
{
    Log::info('User.search start', [
        'user_id' => Auth::id(),
        'query'   => $request->all(),
    ]);

    try {
        // Autenticação
        $this->getAuthenticatedUser();

        // validação — parâmetro único "q"
        $request->validate([
            'q' => 'required|string|max:255',
        ], [
            'q.required' => 'Você precisa informar algo para buscar.',
            'q.string'   => 'O termo de busca deve ser uma string.',
            'q.max'      => 'O termo de busca pode ter no máximo 255 caracteres.',
        ]);

        $q = $request->input('q');
        Log::debug('User.search: termo de busca', ['q' => $q]);

        // Monta a query dinâmica
        $users = User::query()
            ->where(function ($builder) use ($q) {
                // busca exata por ID
                if (ctype_digit($q)) {
                    $builder->orWhere('id', (int) $q);
                }
                // busca parcial por email, cpf, nome
                $builder->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('cpf',   'like', "%{$q}%")
                        ->orWhere('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name',  'like', "%{$q}%");
            })
            ->with('profile') // se quiser trazer relacionamento
            ->orderBy('first_name')
            ->paginate(15);

        Log::info('User.search success', [
            'q'      => $q,
            'count'  => $users->total(),
            'pages'  => $users->lastPage(),
        ]);

        return response()->json([
            'message' => 'Busca concluída com sucesso.',
            'query'   => $q,
            'results' => $users,
        ], 200);

    } catch (ValidationException $ve) {
        Log::warning('ValidationException em User.search', [
            'errors' => $ve->errors(),
            'query'  => $request->all(),
        ]);
        return response()->json(['errors' => $ve->errors()], 422);

    } catch (\Exception $e) {
        Log::error('Exception em User.search', [
            'message' => $e->getMessage(),
            'trace'   => $e->getTraceAsString(),
        ]);
        return response()->json([
            'error' => 'Ocorreu um erro ao buscar usuários.',
            'details' => $e->getMessage(),
        ], 500);
    }
}
}
