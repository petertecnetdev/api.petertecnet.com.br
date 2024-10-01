<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\{User, Interaction};
use App\Mail\VerificationCodeMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Mail\{ResendVerificationCodeMail, ResetPasswordMail};
use Validator;
use Exception;

class AuthController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'first_name.required' => 'O campo nome é obrigatório.',
            'email.required' => 'O campo e-mail é obrigatório.',
            'email.email' => 'O e-mail deve ser um endereço de e-mail válido.',
            'email.unique' => 'Este e-mail já está sendo utilizado por outro usuário. Caso seja seu email você pode recuperar a senha pelo link no formulário.',
            'password.required' => 'O campo senha é obrigatório.',
            'password.min' => 'A senha deve ter no mínimo :min caracteres.',
            'password.regex' => 'A senha deve conter pelo menos uma letra maiúscula,  um número e um caractere especial.',
        ];
    }
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api', ['except' => ['login', 'register', 'sendResetCodeEmail', 'resetPassword']]);
    }

    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
        ], $this->getValidationMessages());

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if (!$token = auth()->attempt($validator->validated())) {
            // Verificar se o e-mail está cadastrado
            $user = User::where('email', $request->email)->first();
            if (!$user) {
                return response()->json(['error' => 'Este e-mail não está cadastrado.'], 404);
            }
            // Se o e-mail estiver cadastrado, mas a senha estiver incorreta
            return response()->json(['error' => 'Senha incorreta.'], 401);
        }

        $interaction = new Interaction();
        $interaction->user_id = auth()->user()->id;
        $interaction->interaction_type = 'login';
        $interaction->entity_id = auth()->user()->id;
        $interaction->entity_type = 'user';
        $interaction->save();
        return response()->json([
            'message' => 'Login realizado com sucesso!',
            'token' => $this->createNewToken($token),
            'user' => auth()->user(),
        ], 200);

        
       
    } catch (ValidationException $exception) {
        return response()->json($exception->errors(), 422);
    } catch (\Exception $exception) {
        // Lidar com outras exceções conforme necessário
        return response()->json(['error' => 'Teste Erro durante o login'], 500);
    }
}

    /**
     * Register a User.
     *
     * @return \Illuminate\Http\JsonResponse
     */

    public function register(Request $request)
    {
        try {
            // Validação dos dados de entrada
            $validator = Validator::make($request->all(), [
                'first_name' => 'required',
                'email' => 'required|email|unique:users',
                'password' => 'required|string|min:6|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
            ], $this->getValidationMessages());

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
            $username = Str::slug($request->input('first_name')) . '-' . Str::random(4);

            // Check if the generated username is unique, if not, generate a new one
            while (User::where('user_name', $username)->exists()) {
                $username = Str::slug($request->input('first_name')) . '-' . Str::random(4);
            }
            // Geração do código de verificação
            $verificationCode = Str::random(4);

            // Criação do usuário no banco de dados
            $user = User::create([
                'first_name' => $request->input('first_name'),
                'email' => $request->input('email'),
                'password' => bcrypt($request->input('password')),
                'user_name' => $username,
                'verification_code' => $verificationCode,
            ]);

            // Envio do e-mail de verificação
            Mail::to($user->email)->send(new VerificationCodeMail($verificationCode, $user));

            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'resgister';
            $interaction->entity_id = $user->id;
            $interaction->entity_type = 'user';
            $interaction->save();

            return response()->json(['message' => 'Registro bem-sucedido'], 201);
        } catch (ValidationException $e) {
            // Captura de exceções de validação
            Log::error('ValidationException: ' . $e->getMessage());
            $errors = $e->errors();
            return response()->json(['message' => 'Erro de validação', 'errors' => $errors], 422);
        } catch (\Exception $e) {
            // Captura de outras exceções
            Log::error('Exception: ' . $e->getMessage());
            return response()->json(['message' => 'Erro durante o registro. Por favor, tente novamente.'], 500);
        }
    }

    public function emailVerify(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'verification_code' => 'required|string|size:4', // Supondo que o código tenha 4 caracteres
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $user = auth()->user();

            // Verifica se o email já foi verificado
            if ($user->email_verified_at !== null) {
                return response()->json(['message' => 'O e-mail já foi verificado anteriormente'], 200);
            }

            $verificationCode = $request->input('verification_code');

            if ($user->verification_code === $verificationCode) {
                $user->email_verified_at = now();
                $user->verification_code = null; // Limpa o código de verificação após a validação
                $user->save();

                $interaction = new Interaction();
                $interaction->user_id = $user->id;
                $interaction->interaction_type = 'verification';
                $interaction->entity_id = $user->id;
                $interaction->entity_type = 'user';
                $interaction->save();

                return response()->json(['message' => 'E-mail verificado com sucesso']);
            } else {
                return response()->json(['error' => 'Código de verificação inválido'], 422);
            }
        } catch (ValidationException $e) {
            return response()->json(['error' => 'Erro de validação', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Exception: ' . $e->getMessage());
            return response()->json(['error' => 'Erro durante a verificação do e-mail'], 500);
        }
    }

    public function changePassword(Request $request)
    {
        try {
            $user = auth()->user();

            // Validação dos dados recebidos
            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string|min:6',
                'new_password' => 'required|string|min:6|different:current_password',
                'confirm_password' => 'required|string|same:new_password',
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            // Verificar se a senha atual do usuário está correta
            if (!Hash::check($request->input('current_password'), $user->password)) {
                throw new \Exception('A senha atual está incorreta');
            }

            // Atualizar a senha do usuário
            $user->password = bcrypt($request->input('new_password'));
            $user->save();
            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'changePassword';
            $interaction->entity_id = $user->id;
            $interaction->entity_type = 'user';
            $interaction->save();
            return response()->json(['message' => 'Senha atualizada com sucesso']);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'Erro de validação', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Exception: ' . $e->getMessage());
            return response()->json(['error' => 'Erro durante a atualização da senha'], 500);
        }
    }


    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */

    public function logout()
    { $interaction = new Interaction();
        $interaction->user_id = Auth()->user()->id;
        $interaction->interaction_type = 'logout';
        $interaction->entity_id = Auth()->user()->id;
        $interaction->entity_type = 'user';
        $interaction->save();
        
        if (Auth::check()) {
           
            Auth::logout();
            return response()->json(['message' => 'Logout realizado com sucesso']);
        } else {
            return response()->json(['error' => 'Usuário não autenticado'], 401);
        }
    }
    public function sendResetCodeEmail(Request $request)
    {
        try {
            // Validação do e-mail
            $validator = $request->validate([
                'email' => 'required|email',
            ], $this->getValidationMessages());
    
            Log::info('Validação do e-mail realizada com sucesso', ['email' => $request->email]);
    
            // Verifica se o e-mail existe no banco de dados
            $user = User::where('email', $request->email)->first();
    
            if (!$user) {
                Log::warning('E-mail não encontrado no banco de dados', ['email' => $request->email]);
                return response()->json(['message' => 'E-mail  não encontrado.'], 404);
            }
    
            Log::info('Usuário encontrado', ['user_id' => $user->id]);
    
            // Gera um código de redefinição de senha
            $code = Str::random(8);
            Log::info('Código de redefinição de senha gerado', ['code' => $code]);
    
            // Salva o código de redefinição de senha no usuário
            $user->reset_password_code = $code;
            $user->reset_password_expires_at = now()->addMinutes(10); // Define o tempo de expiração para 10 minutos
            $user->save();
            Log::info('Código de redefinição de senha salvo no usuário', ['user_id' => $user->id]);
    
            // Envia o e-mail com o código de redefinição de senha
            Mail::to($user->email)->send(new ResetPasswordMail($code,  $user));
            Log::info('E-mail de redefinição de senha enviado', ['email' => $user->email]);
    
            // Log da geração do código de redefinição de senha
            Log::info('Código de redefinição de senha gerado e enviado por e-mail', ['email' => $request->email, 'code' => $code]);
    
            // Registra a interação
            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'ResetCode';
            $interaction->entity_id = $user->id;
            $interaction->entity_type = 'user';
            $interaction->save();
            Log::info('Interação registrada', ['user_id' => $user->id, 'interaction_type' => 'ResetCode']);
    
            return response()->json(['message' => 'Código de redefinição de senha enviado por e-mail']);
        } catch (ValidationException $e) {
            Log::error('Erro de validação', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Erro de validação: ' . $e->getMessage()], 422);
        } catch (\Exception $e) {
            // Log do erro de envio do e-mail
            Log::error('Erro ao enviar o código de redefinição de senha por e-mail', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Falha ao enviar o código de redefinição de senha por e-mail'], 500);
        }
    }
    

    /**
     * Redefine a senha do usuário.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
public function resetPassword(Request $request)
{
    try {
        // Validação dos dados recebidos
        $request->validate([
            'email' => 'required|email',
            'reset_password_code' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ], $this->getValidationMessages());

        // Verifica se o e-mail existe no banco de dados
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'error' => true,
                'message' => 'E-mail não encontrado.Se você ainda não tem um cadastro, por favor, cadastre-se.'
            ], 404);
        }

        // Verifica se o código de redefinição de senha corresponde e não está expirado
        if ($user->reset_password_code !== $request->reset_password_code || now()->gt($user->reset_password_expires_at)) {
            return response()->json([
                'error' => true,
                'message' => 'Código de redefinição de senha inválido ou expirado'
            ], 400);
        }

        // Atualiza a senha do usuário
        $user->password = Hash::make($request->password);
        $user->reset_password_code = null;
        $user->reset_password_expires_at = null;
        $user->save();

        // Registra a interação do usuário
        $this->logPasswordChangedInteraction($user);

        return response()->json([
            'error' => false,
            'message' => 'Senha redefinida com sucesso. Agora é só digitar suas novas credenciais para efetuar o login.'
        ]);
    } catch (ValidationException $e) {
        return response()->json([
            'error' => true,
            'message' => $e->getMessage()
        ], 422);
    } catch (\Exception $e) {
        Log::error('Erro ao redefinir senha ', ['email' => $request->email, 'error' => $e->getMessage()]);
        return response()->json([
            'error' => true,
            'message' => 'Ocorreu um erro ao redefinir a senha'
        ], 500);
    }
}

    
    
    private function logPasswordChangedInteraction($user)
    {
        $interaction = new Interaction();
        $interaction->user_id = $user->id;
        $interaction->interaction_type = 'PasswordChanged';
        $interaction->entity_id = $user->id;
        $interaction->entity_type = 'user';
        $interaction->save();
    }
    
    public function checkauth()
    {
        if (Auth::check()) {
            return true;
        }
        return false;
    }

    public function refresh()
    {
        return $this->createNewToken(auth()->refresh());
    }
    public function unauthorized()
    {
        return response()->json(['error' => 'Unauthorized'], 401);
    }
    /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        try {

            // Obtém o usuário autenticado e carrega o perfil associado
            $user = User::with('profile')
                ->where('user_name', Auth::user()->user_name)
                ->first();
    
            // Verifica se o usuário foi encontrado
            if (!$user) {
                return response()->json(['error' => 'Usuario não autenticado'], 404);
            }
          $interaction = new Interaction();
            $interaction->user_id = auth()->user()->id;
            $interaction->interaction_type = 'me';
            $interaction->entity_id = auth()->user()->id;
            $interaction->entity_type = 'user';
            $interaction->save();
            // Retorna o usuário com sucesso
            return response()->json($user, 200);
    
        } catch (\Exception $e) {
            // Tratamento de exceção, retornando uma resposta com o erro
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }
    
    

    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function createNewToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => auth()->user()
        ]);
    }
    public function resendCodeEmailVerification()
    {
        try {
    
            // Busca pelo usuário com o e-mail fornecido
            $user = auth()->user();
    
            if (!$user) {
                return response()->json(['message' => 'Nenhum usuário encontrado com este e-mail.'], 404);
            }
    
            // Geração de um novo código de verificação
            $newVerificationCode = Str::random(4);
    
            // Atualização do código de verificação no banco de dados
            $user->verification_code = $newVerificationCode;
            $user->save();
    
            // Envio do e-mail de verificação com o novo código
            Mail::to($user->email)->send(new ResendVerificationCodeMail($newVerificationCode, $user));
            $interaction = new Interaction();
            $interaction->user_id = Auth()->user()->id;
            $interaction->interaction_type = 'ResetCodeVerification';
            $interaction->entity_id = Auth()->user()->id;
            $interaction->entity_type = 'user';
            $interaction->save();
            return response()->json(['message' => 'Novo código de verificação enviado com sucesso.'], 200);
        } catch (ValidationException $e) {
            // Captura de exceções de validação
            Log::error('ValidationException: ' . $e->getMessage());
            $errors = $e->errors();
            return response()->json(['message' => 'Erro de validação', 'errors' => $errors], 422);
        } catch (\Exception $e) {
            // Captura de outras exceções
            Log::error('Exception: ' . $e->getMessage());
            return response()->json(['message' => 'Erro ao reenviar o código de verificação. Por favor, tente novamente.'], 500);
        }
    }

}