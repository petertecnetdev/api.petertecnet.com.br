<?php

use Illuminate\Support\Facades\Route;




Route::get('/', function () {
    return view('welcome'); // Retorna a view 'welcome'
});


Route::get('/login', function () {
    return response()->json(['message' => 'Usuário não autenticado']);
})->name('login');
