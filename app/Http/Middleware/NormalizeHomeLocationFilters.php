<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class NormalizeHomeLocationFilters
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('get') && $this->isHomeRequest($request)) {
            $query = $request->query();

            if (array_key_exists('city', $query)) {
                $city = trim((string) $query['city']);
                if ($city === '' || strcasecmp($city, 'Todas') === 0) {
                    unset($query['city']);
                }
            }

            if (array_key_exists('uf', $query)) {
                $uf = trim((string) $query['uf']);
                if ($uf === '' || strcasecmp($uf, 'ALL') === 0) {
                    unset($query['uf']);
                } else {
                    $query['uf'] = strtoupper($uf);
                }
            }

            $request->query->replace($query);
        }

        return $next($request);
    }

    private function isHomeRequest(Request $request): bool
    {
        return $request->is('api/home/*')
            || $request->is('api/establishment/home/*')
            || $request->is('api/employer/home/*')
            || $request->is('api/item/home/*');
    }
}
