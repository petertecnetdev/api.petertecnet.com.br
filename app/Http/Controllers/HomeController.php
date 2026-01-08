<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Home;

class HomeController extends Controller
{
    public function home(Request $request, $app_id)
    {
        \Log::info('HomeController.home start', [
            'app_id' => $app_id,
            'query' => $request->query(),
            'ip' => $request->ip(),
        ]);

        try {
            $city = $request->query('city');
            $uf   = $request->query('uf');
            $showAll = false;

            if ($city === 'Todas') {
                $city = null;
                $showAll = true;
                \Log::info('HomeController.home city reset (Todas)');
            }

            if ($uf === 'ALL') {
                $uf = null;
                $showAll = true;
                \Log::info('HomeController.home uf reset (ALL)');
            }

            // Se não for “todas” e não tiver filtros, tenta resolver pela localização do IP
            if (!$showAll && (!$city || !$uf)) {
                $ip = $request->ip();

                if ($ip && $ip !== '127.0.0.1') {
                    try {
                        $response = \Illuminate\Support\Facades\Http::timeout(3)
                            ->get("http://ip-api.com/json/{$ip}?fields=status,region,city");

                        if ($response->ok() && $response->json('status') === 'success') {
                            $uf   = $uf ?: strtoupper($response->json('region'));
                            $city = $city ?: $response->json('city');

                            \Log::info('HomeController.home location resolved by IP', [
                                'city' => $city,
                                'uf' => $uf,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        \Log::error('HomeController.home IP lookup error', [
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Monta payload usando Home::build com filtros corretos
            $payload = Home::build($app_id, [
                'city' => $city,
                'uf' => $uf,
                'showAll' => $showAll,
            ]);

            return response()->json([
                'success' => true,
                'city' => $city,
                'uf' => $uf,
                'payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            \Log::error('HomeController.home fatal error', [
                'app_id' => $app_id,
                'city' => $city ?? null,
                'uf' => $uf ?? null,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar a home',
            ], 500);
        }
    }
}
