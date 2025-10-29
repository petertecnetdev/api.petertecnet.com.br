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
    ReportController,
    ServiceRecordController,
    EstablishmentController,
    OrderController,
    MenuController,
    EmployerController,
    OrderForecastController,
    EmployerScheduleController

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
    Route::post('/resend-code-email-verification', [AuthController::class, 'resendCodeEmailVerification'])
        ->middleware('auth:api')
        ->name('resendVerificationCode');
});// Autenticação via Google

Route::post('auth/google', [AuthController::class, 'googleAuth']);
Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'user'
], function () {
    Route::get('/', [UserController::class, 'list'])->name('user.list');
    Route::get('/search', [UserController::class, 'search'])->name('user.search');
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
    Route::get('/cnpj/get-company-info', [ProductionController::class, 'getCompanyInfo'])
        ->name('production.getCompanyInfo');
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
    Route::get('/', [BarbershopController::class, 'list'])->name('barbershop.list');
    Route::get('/show/{id}', [BarbershopController::class, 'show'])->name('barbershop.show');
    Route::get('/view/{slug}', [BarbershopController::class, 'view'])->name('barbershop.view');
});

Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'barbershop'
], function ($router) {
    Route::post('/', [BarbershopController::class, 'store'])->name('barbershop.store');
    Route::get('/myBarbershops', [BarbershopController::class, 'myBarbershops'])->name('barbershop.myBarbershops');
    Route::post('/{id}', [BarbershopController::class, 'update'])->name('barbershop.update');
    Route::delete('/{id}', [BarbershopController::class, 'destroy'])->name('barbershop.destroy');
    Route::get('/user', [BarbershopController::class, 'listByUser'])->name('barbershop.listByUser');
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'news'
], function () {
    Route::post('/', [NewsController::class, 'store'])->name('news.store');
    Route::get('/', [NewsController::class, 'list'])->name('news.list');
    Route::get('/show/{id}', [NewsController::class, 'show'])->name('news.show');
    Route::post('/{id}', [NewsController::class, 'update'])->name('news.update');
    Route::delete('/{id}', [NewsController::class, 'destroy'])->name('news.destroy');
    Route::get('/search', [NewsController::class, 'search'])->name('news.search');
    Route::post('/{id}/comment', [NewsController::class, 'comment'])->name('news.comment');
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'barber'
], function () {
    Route::post('/', [BarberController::class, 'store'])->name('barber.store');
    Route::delete('/', [BarberController::class, 'destroy'])->name('barber.destroy');
    Route::get('/show/{id}', [BarberController::class, 'show'])->name('barber.showById');
    Route::get('/{username}', [BarberController::class, 'view'])->name('barber.view');
    Route::get('/', [BarberController::class, 'list'])->name('barber.list');
    Route::post('/{id}', [BarberController::class, 'update'])->name('barber.update');
});


Route::group([
    'middleware' => 'api',
    'prefix' => 'appointment'
], function ($router) {
    Route::post('/', [AppointmentController::class, 'store'])->name('appointment.store');
    Route::get('/listmy', [AppointmentController::class, 'listMy'])->name('appointment.listMy');
    Route::get('/listbyentity', [AppointmentController::class, 'listByEntity'])->name('appointment.listByEntity');
    Route::get('/listbyprovider', [AppointmentController::class, 'listByProvider'])->name('appointment.listByProvider');
    Route::get('/listbyclient', [AppointmentController::class, 'listByClient'])->name('appointment.listByClient');

    // disponibilidade de hor�rios para um barbeiro em uma data
    Route::get('/availability', [AppointmentController::class, 'availability'])->name('appointment.availability');

    Route::delete('/{id}', [AppointmentController::class, 'destroy'])->name('appointment.destroy');
    Route::patch('/{id}/status', [AppointmentController::class, 'updateStatus'])->name('appointment.updateStatus');
});




Route::group([
    'middleware' => 'api',
    'prefix' => 'report',
], function () {
    Route::post('/order', [ReportController::class, 'order'])->name('report.order');
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'service-record'
], function ($router) {
    Route::post('/', [ServiceRecordController::class, 'store'])->name('service_record.store');
    Route::get('/listmy', [ServiceRecordController::class, 'listMy'])->name('service_record.listMy');
    Route::get('/listbyclient', [ServiceRecordController::class, 'listByClient'])->name('service_record.listByClient');
    Route::get('/listbyentity', [ServiceRecordController::class, 'listByEntity'])->name('service_record.listByEntity');
    Route::get('/listbyprovider', [ServiceRecordController::class, 'listByProvider'])->name('service_record.listByProvider');
    Route::delete('/{id}', [ServiceRecordController::class, 'destroy'])->name('service_record.destroy');
    Route::patch('/{id}/status', [ServiceRecordController::class, 'updateStatus'])->name('service_record.updateStatus');

});
Route::prefix('establishment')
    ->middleware('api')
    ->group(function () {
        Route::get('/', [EstablishmentController::class, 'list'])->name('establishment.list');
        Route::get('/category/{category}', [EstablishmentController::class, 'listByCategory'])->name('establishment.listByCategory');
        Route::get('/show/{id}', [EstablishmentController::class, 'show'])->name('establishment.show');
        Route::get('/view/{slug}', [EstablishmentController::class, 'view'])->name('establishment.view');
    });

Route::prefix('establishment')
    ->middleware(['api', 'auth:api'])
    ->group(function () {
        Route::post('/', [EstablishmentController::class, 'store'])->name('establishment.store');
        Route::post('/{id}', [EstablishmentController::class, 'update'])->name('establishment.update');
        Route::delete('/{id}', [EstablishmentController::class, 'destroy'])->name('establishment.destroy');
        Route::get('/my', [EstablishmentController::class, 'myEstablishments'])->name('establishment.my');
        Route::get('/user', [EstablishmentController::class, 'listByUser'])->name('establishment.listByUser');
        Route::get('/my/category/{category}', [EstablishmentController::class, 'listMyByCategory'])->name('establishment.listMyByCategory');
    });


Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'order'
], function () {
    Route::post('/', [OrderController::class, 'store'])->name('order.store');
    Route::get('/listbyentity', [OrderController::class, 'listByEntity'])->name('order.listByEntity');
    Route::get('/listbyemployer', [OrderController::class, 'listByEmployer'])->name('order.listByEmployer');
    Route::get('/{id}', [OrderController::class, 'show'])->whereNumber('id')->name('order.show');
    Route::put('/{id}', [OrderController::class, 'update'])->whereNumber('id')->name('order.update');
    Route::put('/{id}/update-appointment-status', [OrderController::class, 'updateAppointmentStatus'])->whereNumber('id')->name('order.updateAppointmentStatus');
});

// Rotas públicas (listar e visualizar itens)
Route::group([
    'middleware' => 'api',
    'prefix' => 'item'
], function ($router) {
    Route::get('/', [ItemController::class, 'listByEntity'])->name('item.listByEntity');
    Route::get('/listbyapp', [ItemController::class, 'listAll'])->name('item.listByApp');
    Route::get('/listall', [ItemController::class, 'listAll'])->name('item.listAll');
    Route::get('/listservicesbyentity', [ItemController::class, 'listServicesByEntity'])->name('item.listServicesByEntity');
    Route::get('/{id}', [ItemController::class, 'show'])->name('item.show');
    Route::get('/view/{slug}', [ItemController::class, 'view'])->name('item.view');
});

// Rotas autenticadas (criação, atualização, exclusão e aumento de preços)
Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'item'
], function () {
    Route::post('/increase-prices', [ItemController::class, 'increasePricesByPercentage'])->name('item.increasePricesByPercentage');
    Route::post('/', [ItemController::class, 'store'])->name('item.store');
    Route::post('/bulk', [ItemController::class, 'storeBulk'])->name('item.storeBulk');
    Route::post('/{id}', [ItemController::class, 'update'])->name('item.update');
    Route::delete('/{id}', [ItemController::class, 'destroy'])->name('item.destroy');
});


Route::group([
    'middleware' => 'api',
], function () {
    // Public
    Route::get('menu', [MenuController::class, 'list'])->name('menu.list');
    Route::get('menu/{id}', [MenuController::class, 'show'])->name('menu.show');
});

Route::group([
    'middleware' => ['api', 'auth:api'],
], function () {
    // Protected
    Route::post('menu', [MenuController::class, 'store'])->name('menu.store');
    Route::put('menu/{id}', [MenuController::class, 'update'])->name('menu.update');
    Route::delete('menu/{id}', [MenuController::class, 'destroy'])->name('menu.destroy');
});



// Rotas para OrderForecastController
Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'order-forecast',
], function () {
    // Listar previs�es por intervalo de datas e entidade
    Route::get('/', [OrderForecastController::class, 'index'])->name('orderForecast.index');
    // Gerar previs�es para intervalo de datas e entidade (sem deletar registros antigos)
    Route::post('/generate', [OrderForecastController::class, 'generate'])->name('orderForecast.generate');
});
Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'employer'
], function () {
    Route::post('/', [App\Http\Controllers\EmployerController::class, 'store'])->name('employer.store');
    Route::get('/list', [App\Http\Controllers\EmployerController::class, 'listByEstablishment'])->name('employer.list');
    Route::post('/detach', [App\Http\Controllers\EmployerController::class, 'detach'])->name('employer.detach');
    Route::get('/check-updates', [App\Http\Controllers\EmployerController::class, 'checkUpdates'])->name('employer.checkUpdates');
    Route::get('/appointments', [App\Http\Controllers\EmployerController::class, 'listAppointments'])->name('employer.appointments'); // ✅ nova rota
});


Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'employer-schedule'
], function () {
    Route::get('/', [EmployerScheduleController::class, 'index'])->name('employerSchedule.index');
    Route::post('/', [EmployerScheduleController::class, 'store'])->name('employerSchedule.store');
    Route::delete('/{id}', [EmployerScheduleController::class, 'destroy'])->name('employerSchedule.destroy');
    Route::get('/available', [EmployerScheduleController::class, 'availableTimes'])->name('employerSchedule.availableTimes');
    Route::post('/reserve', [EmployerScheduleController::class, 'reserve'])->name('employerSchedule.reserve');
});