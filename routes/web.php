<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api', function () {
    return response()->json(['message' => 'API está pronta para ser usada']);
});

Route::get('/login', function () {
    return response()->json(['message' => 'Usuário não autenticado']);
})->name('login');
