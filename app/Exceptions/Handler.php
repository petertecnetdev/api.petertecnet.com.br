<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
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
        //
    }

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        return $request->expectsJson()
<<<<<<< HEAD
            ? response()->json(['error' => 'Não autenticado.'], 401)
=======
            ? response()->json(['error' => 'NÃ£o autenticado.'], 401)
>>>>>>> develop
            : redirect()->guest(route('login'));
    }

    public function render($request, Throwable $e)
    {
        try {
            return parent::render($request, $e);
        } catch (Throwable $jsonError) {
            return response()->json([
                'error' => 'Erro interno.',
            ], 500);
        }
    }

    protected function prepareJsonResponse($request, Throwable $e)
    {
        return response()->json([
            'error' => 'Erro interno.',
        ], 500);
    }
}
