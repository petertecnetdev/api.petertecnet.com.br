<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $levels = [];

    protected $dontReport = [];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register()
    {
        $this->reportable(function (Throwable $e) {
            $request = request();

            Log::error('Unhandled API exception', [
                'request_id' => $request?->attributes->get('request_id'),
                'method' => $request?->method(),
                'path' => $request?->path(),
                'user_id' => $request?->user()?->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        });
    }

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if (! $request->expectsJson()) {
            return redirect()->guest(route('login'));
        }

        return $this->jsonError($request, 'Não autenticado.', 401, 'UNAUTHENTICATED');
    }

    public function render($request, Throwable $e)
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return parent::render($request, $e);
        }

        if ($e instanceof ValidationException) {
            return response()->json([
                'success' => false,
                'message' => 'Os dados enviados são inválidos.',
                'error' => 'Os dados enviados são inválidos.',
                'code' => 'VALIDATION_ERROR',
                'errors' => $e->errors(),
                'request_id' => $request->attributes->get('request_id'),
            ], 422);
        }

        if ($e instanceof AuthenticationException) {
            return $this->jsonError($request, 'Não autenticado.', 401, 'UNAUTHENTICATED');
        }

        if ($e instanceof AuthorizationException) {
            return $this->jsonError($request, 'Acesso negado.', 403, 'FORBIDDEN');
        }

        if ($e instanceof ModelNotFoundException) {
            return $this->jsonError($request, 'Recurso não encontrado.', 404, 'NOT_FOUND');
        }

        if ($e instanceof ThrottleRequestsException) {
            return $this->jsonError($request, 'Muitas requisições. Tente novamente em instantes.', 429, 'RATE_LIMITED');
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = trim($e->getMessage());

            if ($message === '') {
                $message = match ($status) {
                    400 => 'Requisição inválida.',
                    401 => 'Não autenticado.',
                    403 => 'Acesso negado.',
                    404 => 'Recurso não encontrado.',
                    405 => 'Método não permitido.',
                    409 => 'Conflito ao processar a operação.',
                    422 => 'Não foi possível processar a operação.',
                    429 => 'Muitas requisições.',
                    default => $status >= 500 ? 'Erro interno.' : 'Não foi possível processar a requisição.',
                };
            }

            return $this->jsonError($request, $message, $status, $this->statusCode($status));
        }

        report($e);

        return $this->jsonError($request, 'Erro interno.', 500, 'INTERNAL_ERROR');
    }

    private function jsonError($request, string $message, int $status, string $code)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => $message,
            'code' => $code,
            'request_id' => $request->attributes->get('request_id'),
        ], $status);
    }

    private function statusCode(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'UNPROCESSABLE_ENTITY',
            429 => 'RATE_LIMITED',
            default => $status >= 500 ? 'INTERNAL_ERROR' : 'HTTP_ERROR',
        };
    }
}
