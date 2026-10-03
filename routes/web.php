<?php

use App\Domain\Events\Http\Controllers\EventSharePreviewController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('api.landing');

Route::view('/docs', 'api-docs')->name('api.docs');

Route::get('/share/apps/{application}/events/{slug}', EventSharePreviewController::class)
    ->where([
        'application' => '[A-Za-z0-9\-]+',
        'slug' => '[A-Za-z0-9\-]+',
    ])
    ->middleware('throttle:120,1')
    ->name('share.event');

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
