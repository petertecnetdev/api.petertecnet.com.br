<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api', function () {
    return response()->json(['message' => 'API está pronta para ser usada']);
});

Route::get('/developers', fn () => view('developers'))->name('developers');
Route::get('/developers/openapi.yaml', fn () => response()->file(base_path('docs/openapi.yaml'), [
    'Content-Type' => 'application/yaml; charset=utf-8',
]));

Route::get('/login', function () {
    return response()->json(['message' => 'Usuário não autenticado']);
})->name('login');
