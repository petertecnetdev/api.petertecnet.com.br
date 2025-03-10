<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    AppointmentController,
    AuthController,
    UserController,
    ProfileController,
    ProductionController,
    EventController,
    TicketController,
    BarbershopController,
    ItemController,
    NewsController,
    BarberController,
    ReportController
};


Route::group([
    'middleware' => 'api',
    'prefix' => 'auth'
], function ($router) {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/password-email', [AuthController::class, 'sendResetCodeEmail'])->name('passwordEmail');
    Route::post('/password-reset', [AuthController::class, 'resetPassword'])->name('resetPassword');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/refresh', [AuthController::class, 'refresh'])->name('refresh');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:api')->name('me');
    Route::get('/check-auth', [AuthController::class, 'checkauth'])->middleware('auth:api')->name('checkAuth');
    Route::post('/email-verify', [AuthController::class, 'emailVerify'])->middleware('auth:api')->name('emailVerify');
    Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('auth:api')->name('changePassword');
    Route::post('/resend-code-email-verification', [AuthController::class, 'resendCodeEmailVerification'])->middleware('auth:api')->name('resendVerificationCode');
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



Route::group([
    'middleware' => 'api',
    'prefix' => 'barbershop'
], function ($router) {
    Route::post('/', [BarberShopController::class, 'store'])->name('barbershop.store'); // Criar um novo serviço de barbearia
    Route::get('/myBarbershops', [BarberShopController::class, 'myBarbershops'])->name('barbershop.myBarbershops'); // Listar todos os serviços de barbearia
    Route::get('/', [BarberShopController::class, 'list'])->name('barbershop.list'); // Listar todos os serviços de barbearia
    Route::get('/show/{id}', [BarberShopController::class, 'show'])->name('barbershop.show'); // Mostrar detalhes de um serviço específico
    Route::get('/view/{slug}', [BarberShopController::class, 'view'])->name('barbershop.view'); // Mostrar detalhes de um serviço específico
    Route::post('/{id}', [BarberShopController::class, 'update'])->name('barbershop.update'); // Atualizar um serviço de barbearia existente
    Route::delete('/{id}', [BarberShopController::class, 'destroy'])->name('barbershop.destroy'); // Excluir um serviço de barbearia
    Route::get('/user', [BarberShopController::class, 'listByUser'])->name('barbershop.listByUser'); // Listar barbearias do usuário autenticado
});


// Rotas de notícias
Route::group(['middleware' => 'api', 'prefix' => 'news'], function () {
    Route::post('/', [NewsController::class, 'store'])->name('news.store');
    Route::get('/', [NewsController::class, 'list'])->name('news.list');
    Route::get('/show/{id}', [NewsController::class, 'show'])->name('news.show');
    Route::post('/{id}', [NewsController::class, 'update'])->name('news.update');
    Route::delete('/{id}', [NewsController::class, 'destroy'])->name('news.destroy');
    Route::get('/search', [NewsController::class, 'search'])->name('news.search');
    Route::post('/{id}/comment', [NewsController::class, 'comment'])->name('news.comment');
});// Rotas de barbeiros
Route::group([
    'middleware' => 'api',
    'prefix' => 'barber'
], function () {
    Route::post('/', [BarberController::class, 'store'])->name('barber.store');
    Route::delete('/', [BarberController::class, 'destroy'])->name('barber.destroy');
    Route::get('/show/{id}', [BarberController::class, 'showById'])->name('barber.showById');
    Route::get('/{username}', [BarberController::class, 'view'])->name('barber.view');
    Route::get('/', [BarberController::class, 'list'])->name('barber.list');
    Route::post('/{id}', [BarberController::class, 'update'])->name('barber.update');
});


// Rotas de agendamentos
Route::group([
    'middleware' => 'api',
    'prefix' => 'appointment'
], function ($router) {
    Route::post('/', [AppointmentController::class, 'store'])->name('appointment.store');
    Route::get('/listmy', [AppointmentController::class, 'listMy'])->name('appointment.listMy');
    Route::get('/listbyentity', [AppointmentController::class, 'listByEntity'])->name('appointment.listByEntity');
    Route::get('/listbyprovider', [AppointmentController::class, 'listByProvider'])->name('appointment.listByProvider');
    Route::get('/listbyclient', [AppointmentController::class, 'listByClient'])->name('appointment.listByClient');
    Route::delete('/{id}', [AppointmentController::class, 'destroy'])->name('appointment.destroy');
});


Route::group([
    'middleware' => 'api',
    'prefix' => 'item'
], function ($router) {
    Route::post('/', [ItemController::class, 'store'])->name('item.store');
    Route::get('/', [ItemController::class, 'listByEntity'])->name('item.listByEntity');
    Route::delete('/{id}', [ItemController::class, 'destroy'])->name('item.destroy');
    Route::post('/{id}', [ItemController::class, 'update'])->name('item.update');
    Route::get('/listbyapp', [ItemController::class, 'listAll'])->name('item.listByApp');
    Route::get('/listall', [ItemController::class, 'listAll'])->name('item.listAll');
    Route::get('/listservicesbyentity', [ItemController::class, 'listServicesByEntity'])->name('item.listServicesByEntity');
    Route::get('/{id}', [ItemController::class, 'show'])->name('item.show'); // Nova rota para exibir um item específico
});




// Rotas para geração de relatórios
Route::group([
    'middleware' => 'api',
    'prefix' => 'report'
], function ($router) {
    Route::post('/generate', [ReportController::class, 'generatePDF'])->name('report.generate');
});
