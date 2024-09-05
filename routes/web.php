<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;



Route::get('/', function () {
    return response()->json(['message' => 'API está pronta para ser usada']);
});

Route::get('/login', function () {
    return response()->json(['message' => 'Usuário não autenticado']);
})->name('login');
