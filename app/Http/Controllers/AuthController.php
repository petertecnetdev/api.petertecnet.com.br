<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\{User, Interaction};
use App\Mail\VerificationCodeMail;
use App\Mail\{ResendVerificationCodeMail, ResetPasswordMail};
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Validator;
use Exception;
use Illuminate\Foundation\Auth\ThrottlesLogins;
use Illuminate\Cache\RateLimiter;

class AuthController extends Controller
{
    use ThrottlesLogins;

    /**
     * Retorna a chave para o throttle (limitação de tentativas).
     */
    public function username()
    {
        return 'email';
    }

    /**
     * Mensagens de validação customizadas.
     */
    protected function getValidationMessages()
    {
        return [
            'first_name.required' => 'O campo nome é obrigatório.',
            'first_name.regex' => 'O nome não pode conter caracteres especiais ou números.',
            'email.required' => 'O campo e-mail é obrigatório.',
            'email.email' => 'O e-mail deve ser um endereço de e-mail válido.',
            'email.unique' => 'Este e-mail já está sendo utilizado por outro usuário. Caso seja seu e-mail, você pode recuperar a senha pelo link no formulário.',
            'password.required' => 'O campo senha é obrigatório.',
            'password.min' => 'A senha deve ter no mínimo :min caracteres.',
            'password.regex' => 'A senha deve conter pelo menos uma letra maiúscula, uma letra minúscula, um número e um caractere especial (@, $, !, %, *, ?, &).',
            'verification_code.required' => 'O campo código de verificação é obrigatório.',
            'verification_code.string' => 'O código de verificação deve ser uma sequência de caracteres.',
            'verification_code.size' => 'O código de verificação deve ter exatamente :size caracteres.',
            'reset_password_code.required' => 'O campo código de redefinição de senha é obrigatório.',
            'reset_password_code.string' => 'O código de redefinição de senha deve ser uma sequência de caracteres.',
            'reset_password_code.size' => 'O código de redefinição de senha deve ter exatamente :size caracteres.',
            'current_password.required' => 'O campo senha atual é obrigatório.',
            'current_password.regex' => 'A senha atual deve conter pelo menos uma letra maiúscula, uma letra minúscula, um número e um caractere especial (@, $, !, %, *, ?, &).',
            'password_confirmation.required' => 'O campo confirmação de senha é obrigatório.',
            'password_confirmation.same' => 'A confirmação de senha deve coincidir com a senha.',
            'default' => 'Um erro ocorreu. Por favor, tente novamente.',
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
            Log::info('Tentativa de login', ['email' => $request->email]);

            $validator = Validator::make($request->all(), [
                'email'    => 'required|email',
                'password' => 'required|string|min:6|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
            ], $this->getValidationMessages());

            if ($validator->fails()) {
                Log::warning('Falha na validação do login', ['erros' => $validator->errors()]);
                throw new ValidationException($validator);
            }

            // Verifica se há muitas tentativas de login
            if ($this->hasTooManyLoginAttempts($request)) {
                $this->fireLockoutEvent($request);
                return $this->sendLockoutResponse($request);
            }

            if (!$token = auth()->attempt($validator->validated())) {
                Log::warning('Falha na autenticação', ['email' => $request->email]);
                $this->incrementLoginAttempts($request);

                // Verifica se o e-mail está cadastrado
                $user = User::where('email', $request->email)->first();
                if (!$user) {
                    Log::error('Tentativa de login com e-mail não cadastrado', ['email' => $request->email]);
                    return response()->json(['error' => 'Este e-mail não está cadastrado.'], 404);
                }

                Log::error('Senha incorreta para o e-mail', ['email' => $request->email]);
                return response()->json(['error' => 'Senha incorreta.'], 401);
            }

            // Login bem-sucedido: limpa as tentativas
            $this->clearLoginAttempts($request);

            Log::info('Login realizado com sucesso', ['user_id' => auth()->user()->id]);

            $interaction = new Interaction();
            $interaction->user_id = auth()->user()->id;
            $interaction->interaction_type = 'login';
            $interaction->entity_id = auth()->user()->id;
            $interaction->entity_type = 'user';
            $interaction->save();

            return response()->json([
                'message' => 'Login realizado com sucesso!',
                'token'   => $this->createNewToken($token)->getData()->access_token,
                'user'    => auth()->user(),
            ], 200);

        } catch (ValidationException $exception) {
            Log::error('Erro de validação no login', ['erros' => $exception->errors()]);
            return response()->json($exception->errors(), 422);
        } catch (\Exception $exception) {
            Log::error('Erro inesperado durante o login', [
                'message' => $exception->getMessage(),
                'trace'   => $exception->getTraceAsString()
            ]);
            return response()->json(['error' => 'Erro durante o login'], 500);
        }
    }

    /**
     * Retorna a resposta customizada em caso de muitas tentativas.
     */
    protected function sendLockoutResponse(Request $request)
    {
        $seconds = app(RateLimiter::class)->availableIn($this->throttleKey($request));
        return response()->json([
            'error' => "Muitas solicitações. Aguarde um momento e tente novamente. Tempo restante: {$seconds} segundos."
        ], 429);
    }

    /**
     * Register a User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'first_name' => ['required', 'regex:/^[a-zA-ZÀ-ÿ\s]+$/'],
                'email'      => 'required|email|unique:users',
                'password'   => 'required|string|min:6|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
            ], $this->getValidationMessages());

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $username = Str::slug($request->input('first_name')) . '-' . Str::random(4);
            while (User::where('user_name', $username)->exists()) {
                $username = Str::slug($request->input('first_name')) . '-' . Str::random(4);
            }

            $verificationCode = Str::random(4);

            $user = User::create([
                'first_name'        => $request->input('first_name'),
                'email'             => $request->input('email'),
                'password'          => bcrypt($request->input('password')),
                'user_name'         => $username,
                'verification_code' => $verificationCode,
            ]);

            Mail::to($user->email)->send(new VerificationCodeMail($verificationCode, $user));

            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'resgister';
            $interaction->entity_id = $user->id;
            $interaction->entity_type = 'user';
            $interaction->save();

            return response()->json(['message' => 'Registro bem-sucedido'], 201);
        } catch (ValidationException $e) {
            Log::error('ValidationException: ' . $e->getMessage());
            $errors = $e->errors();
            return response()->json(['message' => 'Erro de validação', 'errors' => $errors], 422);
        } catch (\Exception $e) {
            Log::error('Exception: ' . $e->getMessage());
            return response()->json(['message' => 'Erro durante o registro. Por favor, tente novamente.'], 500);
        }
    }

    public function emailVerify(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'verification_code' => 'required|string|size:4',
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $user = auth()->user();

            if ($user->email_verified_at !== null) {
                return response()->json(['message' => 'O e-mail já foi verificado anteriormente'], 200);
            }

            $verificationCode = $request->input('verification_code');

            if ($user->verification_code === $verificationCode) {
                $user->email_verified_at = now();
                $user->verification_code = null;
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

            $validator = Validator::make($request->all(), [
                'current_password'      => 'required|string|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
                'new_password'          => 'required|string|min:6|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/|different:current_password',
                'password_confirmation' => 'required|string|min:6|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
            ], $this->getValidationMessages());

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            if (!Hash::check($request->input('current_password'), $user->password)) {
                return response()->json(['error' => 'A senha atual está incorreta.'], 401);
            }

            $user->password = bcrypt($request->input('new_password'));
            $user->save();

            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'password_change';
            $interaction->entity_id = $user->id;
            $interaction->entity_type = 'user';
            $interaction->save();

            return response()->json(['message' => 'Senha alterada com sucesso!'], 200);
        } catch (ValidationException $exception) {
            return response()->json($exception->errors(), 422);
        } catch (\Exception $exception) {
            Log::error('Exception: ' . $exception->getMessage());
            return response()->json(['error' => 'Erro durante a alteração da senha'], 500);
        }
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        $interaction = new Interaction();
        $interaction->user_id = Auth::user()->id;
        $interaction->interaction_type = 'logout';
        $interaction->entity_id = Auth::user()->id;
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
            $request->validate([
                'email' => 'required|email',
            ], $this->getValidationMessages());

            Log::info('Validação do e-mail realizada com sucesso', ['email' => $request->email]);

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                Log::warning('E-mail não encontrado no banco de dados', ['email' => $request->email]);
                return response()->json(['message' => 'E-mail não encontrado.'], 404);
            }

            Log::info('Usuário encontrado', ['user_id' => $user->id]);

            $code = Str::random(8);
            Log::info('Código de redefinição de senha gerado', ['code' => $code]);

            $user->reset_password_code = $code;
            $user->reset_password_expires_at = now()->addMinutes(10);
            $user->save();
            Log::info('Código de redefinição de senha salvo no usuário', ['user_id' => $user->id]);

            Mail::to($user->email)->send(new ResetPasswordMail($code, $user));
            Log::info('E-mail de redefinição de senha enviado', ['email' => $user->email]);

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
            Log::error('Erro ao enviar o código de redefinição de senha por e-mail', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Falha ao enviar o código de redefinição de senha por e-mail'], 500);
        }
    }

    public function resetPassword(Request $request)
    {
        try {
            Log::info('Iniciando validação para redefinir senha', ['email' => $request->email]);

            $request->validate([
                'email'              => 'required|email',
                'reset_password_code'=> 'required|string|size:8',
                'password'           => [
                    'required',
                    'string',
                    'min:6',
                    'regex:/[A-Z]/',
                    'regex:/[a-z]/',
                    'regex:/[!@#$%^&*(),.?":{}|<>]/',
                ]
            ], $this->getValidationMessages());

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'error' => true,
                    'message' => 'E-mail não encontrado. Se você ainda não tem um cadastro, por favor, cadastre-se.'
                ], 404);
            }

            if ($user->reset_password_code !== $request->reset_password_code || now()->gt($user->reset_password_expires_at)) {
                return response()->json([
                    'error' => true,
                    'message' => 'Código de redefinição de senha inválido ou expirado. Por favor, solicite um novo código.'
                ], 400);
            }

            $user->password = Hash::make($request->password);
            $user->reset_password_code = null;
            $user->reset_password_expires_at = null;
            $user->save();

            $this->logPasswordChangedInteraction($user);

            return response()->json([
                'error' => false,
                'message' => 'Senha redefinida com sucesso. Agora é só digitar suas novas credenciais para efetuar o login.'
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => true,
                'message' => $e->validator->errors()->first()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao redefinir senha', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'error' => true,
                'message' => 'Ocorreu um erro ao redefinir a senha. Tente novamente mais tarde.'
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
        return Auth::check();
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
            $user = User::with('profile')
                ->where('user_name', Auth::user()->user_name)
                ->first();

            if (!$user) {
                return response()->json(['error' => 'Usuário não autenticado'], 404);
            }

            $interaction = new Interaction();
            $interaction->user_id = Auth::user()->id;
            $interaction->interaction_type = 'me';
            $interaction->entity_id = Auth::user()->id;
            $interaction->entity_type = 'user';
            $interaction->save();

            return response()->json([
                'message' => 'Usuário encontrado com sucesso.',
                'user'    => $user,
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     * @return \Illuminate\Http\JsonResponse
     */
    protected function createNewToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => auth()->factory()->getTTL() * 60,
            'user'         => auth()->user()
        ]);
    }

    public function resendCodeEmailVerification()
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json(['message' => 'Nenhum usuário encontrado com este e-mail.'], 404);
            }

            $newVerificationCode = Str::random(4);

            $user->verification_code = $newVerificationCode;
            $user->save();

            Mail::to($user->email)->send(new ResendVerificationCodeMail($newVerificationCode, $user));

            $interaction = new Interaction();
            $interaction->user_id = Auth::user()->id;
            $interaction->interaction_type = 'ResetCodeVerification';
            $interaction->entity_id = Auth::user()->id;
            $interaction->entity_type = 'user';
            $interaction->save();

            return response()->json(['message' => 'Novo código de verificação enviado com sucesso.'], 200);
        } catch (ValidationException $e) {
            Log::error('ValidationException: ' . $e->getMessage());
            $errors = $e->errors();
            return response()->json(['message' => 'Erro de validação', 'errors' => $errors], 422);
        } catch (\Exception $e) {
            Log::error('Exception: ' . $e->getMessage());
            return response()->json(['message' => 'Erro ao reenviar o código de verificação. Por favor, tente novamente.'], 500);
        }
    }
}
