<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('api.landing');
Route::view('/docs', 'api-docs')->name('api.docs');
Route::view('/developers', 'developer-portal')->name('api.developers');
Route::view('/status', 'api-status')->name('api.status');
Route::view('/changelog', 'api-changelog')->name('api.changelog');
Route::view('/terms', 'api-terms')->name('api.terms');
Route::view('/privacy', 'api-privacy')->name('api.privacy');
Route::view('/deprecation', 'api-deprecation')->name('api.deprecation');

Route::get('/openapi.json', function () {
    return response()->file(public_path('openapi.json'), [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'public, max-age=300',
    ]);
})->name('api.openapi');

Route::get('/api', function () {
    return response()->json([
        'name' => 'Peter Tecnet Public API',
        'description' => 'API pública, versionada e multiplataforma para integrações externas com o ecossistema Peter Tecnet.',
        'visibility' => 'public',
        'format' => 'JSON',
        'transport' => 'HTTPS',
        'stable_version' => 'v1',
        'base_url' => url('/api/v1'),
        'sandbox_base_url' => url('/api/sandbox/v1'),
        'documentation' => url('/docs'),
        'openapi' => url('/openapi.json'),
        'developer_portal' => url('/developers'),
        'status' => url('/api/v1/status'),
        'status_page' => url('/status'),
        'changelog' => url('/changelog'),
        'terms' => url('/terms'),
        'privacy' => url('/privacy'),
        'deprecation_policy' => url('/deprecation'),
        'website' => 'https://petertecnet.com.br',
        'authentication' => [
            'public_api' => 'X-API-Key com scopes por integração',
            'developer_control_plane' => 'Bearer token da conta Peter Tecnet',
        ],
    ]);
})->name('api.discovery');

Route::get('/login', function () {
    return response()->json(['message' => 'Usuário não autenticado']);
})->name('login');
