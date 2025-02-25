<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Cors
{
    /**
     * Manipula a requisição adicionando os headers CORS.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Para requisições preflight (OPTIONS), retorna resposta imediata
        if ($request->getMethod() === 'OPTIONS') {
            $response = response()->json('OK', 200);
        } else {
            $response = $next($request);
        }

        // Define os headers CORS necessários
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

        return $response;
    }
}
