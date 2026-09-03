<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class PublicAuthConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $googleClientId = trim((string) config('services.google.client_id', ''));

        return response()->json([
            'google' => [
                'enabled' => $googleClientId !== '',
                'client_id' => $googleClientId !== '' ? $googleClientId : null,
            ],
        ])->header('Cache-Control', 'public, max-age=300');
    }
}
