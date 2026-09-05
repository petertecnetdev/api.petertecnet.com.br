<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PeterTecnetAdmin
{
    private const ADMIN_EMAIL = 'petertecnet@gmail.com';

    public function handle(Request $request, Closure $next): Response
    {
        $verified = $request->user() && strtolower((string) $request->user()->email) === self::ADMIN_EMAIL;
        if (! $verified) {
            $request->session()->put('peter_admin_return_url', $this->safeReturnUrl($request));
            return redirect()->route('admin.gate');
        }
        return $next($request);
    }

    private function safeReturnUrl(Request $request): string
    {
        $previous = url()->previous();
        if ($previous && !str_starts_with($previous, url('/admin'))) return $previous;
        return 'https://petertecnet.com.br/';
    }
}
