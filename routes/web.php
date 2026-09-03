<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('api.landing');

Route::view('/docs', 'api-docs')->name('api.docs');

Route::get('/api', function () {
    return response()->json([
        'name' => 'Peter Tecnet API',
        'description' => 'API pública e multiplataforma para integrações com o ecossistema Peter Tecnet.',
        'visibility' => 'public',
        'format' => 'JSON',
        'transport' => 'HTTPS',
        'base_url' => url('/api'),
        'documentation' => url('/docs'),
        'website' => 'https://petertecnet.com.br',
        'authentication' => 'Bearer token em endpoints protegidos',
    ]);
})->name('api.discovery');

Route::get('/login', function () {
    return response()->json(['message' => 'Usuário não autenticado']);
})->name('login');
