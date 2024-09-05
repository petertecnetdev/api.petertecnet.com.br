<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{AuthController, UserController, ProfileController,
     ProductionController, EventController, TicketController, PaymentController};

Route::group([
    'middleware' => 'api',
    'prefix' => 'auth'
], function ($router) {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/password-email', [AuthController::class, 'sendResetCodeEmail'])->name('passwordEmail');
    Route::post('/password-reset', [AuthController::class, 'resetPassword'])->name('passwordReset');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:api')->name('me');
    Route::get('/checkauth', [AuthController::class, 'checkauth'])->middleware('auth:api')->name('checkAuth');
    Route::post('/email-verify', [AuthController::class, 'emailVerify'])->middleware('auth:api')->name('emailVerify');
    Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('auth:api')->name('changePassword');
    Route::post('/password-update', [AuthController::class, 'resetPassword'])->name('passwordUpdate'); // Corrigido o nome da rota
    Route::post('/resend-code-email-verification', [AuthController::class, 'resendCodeEmailVerification'])->middleware('auth:api')->name('verification.resend'); // Corrigido o nome da rota
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'user'
], function ($router) {
    Route::get('/', [UserController::class, 'list'])->name('user.list');
    Route::get('/show/{id}', [UserController::class, 'show'])->name('user.show'); 
    Route::get('/{userName}', [UserController::class, 'view'])->name('user.view'); 
    Route::post('/new', [UserController::class, 'store'])->name('user.store');
    Route::post('/{user}', [UserController::class, 'update'])->name('user.update'); 
    Route::delete('/{id}', [UserController::class, 'destroy'])->name('user.destroy');
});



Route::group([
    'middleware' => 'api',
    'prefix' => 'profile'
], function ($router) {
    Route::get('/', [ProfileController::class, 'list'])->name('profile.list');
    Route::get('/{id}', [ProfileController::class, 'show'])->name('profile.show');
    Route::post('/', [ProfileController::class, 'store'])->name('profile.store');
    Route::put('/{id}', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/{id}', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'production'
], function ($router) {
    Route::post('/', [ProductionController::class, 'store'])->name('production.store');
    Route::get('/', [ProductionController::class, 'list'])->name('production.list');
    Route::get('/show/{id}', [ProductionController::class, 'show'])->name('production.show'); 
    Route::post('/{id}', [ProductionController::class, 'update'])->name('production.update');
    Route::delete('/{id}', [ProductionController::class, 'delete'])->name('production.delete'); 
    Route::get('/{slug}', [ProductionController::class, 'view'])->name('production.view'); 
    Route::get('/cnpj/get-company-info', [ProductionController::class, 'getCompanyInfo'])->name('production.getCompanyInfo'); 
  
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'event'
], function ($router) {
    Route::post('/', [EventController::class, 'store'])->name('event.store');
    Route::get('/', [EventController::class, 'list'])->name('event.list');
    Route::get('/show/{id}', [EventController::class, 'show'])->name('event.show'); 
    Route::post('/{id}', [EventController::class, 'update'])->name('event.update');
    Route::delete('/{id}', [EventController::class, 'delete'])->name('event.delete'); 
    Route::get('/{slug}', [EventController::class, 'view'])->name('event.view'); 
    Route::get('/myevents/list', [EventController::class, 'myEvents'])->name('event.myevents'); 
});
Route::group([
    'middleware' => 'api',
    'prefix' => 'ticket'
], function ($router) {
    Route::get('/', [TicketController::class, 'list'])->name('ticket.list');
    Route::get('/show/{id}', [TicketController::class, 'show'])->name('ticket.show');
    Route::post('/', [TicketController::class, 'store'])->name('ticket.store');
    Route::put('/{id}', [TicketController::class, 'update'])->name('ticket.update');
    Route::delete('/{id}', [TicketController::class, 'destroy'])->name('ticket.destroy');
    Route::get('/event/{eventId}', [TicketController::class, 'listByEvent'])->name('ticket.listByEvent');
    Route::get('/user', [TicketController::class, 'listByUser'])->name('ticket.listByUser');
    Route::get('/production/{productionId}', [TicketController::class, 'listByProduction'])->name('ticket.listByProduction');
});


Route::post('/payments/create-charge', [PaymentController::class, 'createCharge']);

