<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    AuthController,
    UserController,
    ProfileController,
    ProductionController,
    EventController,
    TicketController,
    ItemController,
    NewsController,
    ReportController,
    ServiceRecordController,
    EstablishmentController,
    OrderController,
    MenuController,
    EmployerController,
    OrderForecastController
};

/*
|--------------------------------------------------------------------------
| AUTHENTICAÇÃO
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->middleware('api')->group(function () {
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
});
Route::post('auth/google', [AuthController::class, 'googleAuth'])->name('auth.google');


/*
|--------------------------------------------------------------------------
| USUÁRIOS
|--------------------------------------------------------------------------
*/
Route::prefix('user')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/', [UserController::class, 'list'])->name('user.list');
    Route::get('/search', [UserController::class, 'search'])->name('user.search');
    Route::get('/show/{id}', [UserController::class, 'show'])->name('user.show');
    Route::get('/{userName}', [UserController::class, 'view'])->name('user.view');
    Route::post('/new', [UserController::class, 'store'])->name('user.store');
    Route::post('/{user}', [UserController::class, 'update'])->name('user.update');
    Route::delete('/{id}', [UserController::class, 'destroy'])->name('user.destroy');
});


/*
|--------------------------------------------------------------------------
| PERFIL
|--------------------------------------------------------------------------
*/
Route::prefix('profile')->middleware('api')->group(function () {
    Route::get('/', [ProfileController::class, 'list'])->name('profile.list');
    Route::get('/{id}', [ProfileController::class, 'show'])->name('profile.show');
    Route::post('/', [ProfileController::class, 'store'])->name('profile.store');
    Route::put('/{id}', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/{id}', [ProfileController::class, 'destroy'])->name('profile.destroy');
});


/*
|--------------------------------------------------------------------------
| PRODUÇÃO
|--------------------------------------------------------------------------
*/
Route::prefix('production')->middleware('api')->group(function () {
    Route::get('/', [ProductionController::class, 'list'])->name('production.list');
    Route::get('/show/{id}', [ProductionController::class, 'show'])->name('production.show');
    Route::get('/{slug}', [ProductionController::class, 'view'])->name('production.view');
    Route::post('/', [ProductionController::class, 'store'])->name('production.store');
    Route::post('/{id}', [ProductionController::class, 'update'])->name('production.update');
    Route::delete('/{id}', [ProductionController::class, 'delete'])->name('production.delete');
    Route::get('/cnpj/get-company-info', [ProductionController::class, 'getCompanyInfo'])->name('production.getCompanyInfo');
});


/*
|--------------------------------------------------------------------------
| EVENTOS E INGRESSOS
|--------------------------------------------------------------------------
*/
Route::prefix('event')->middleware('api')->group(function () {
    Route::get('/', [EventController::class, 'list'])->name('event.list');
    Route::get('/show/{id}', [EventController::class, 'show'])->name('event.show');
    Route::get('/{slug}', [EventController::class, 'view'])->name('event.view');
    Route::get('/myevents/list', [EventController::class, 'myEvents'])->name('event.myevents');
    Route::post('/', [EventController::class, 'store'])->name('event.store');
    Route::post('/{id}', [EventController::class, 'update'])->name('event.update');
    Route::delete('/{id}', [EventController::class, 'delete'])->name('event.delete');
});

Route::prefix('ticket')->middleware('api')->group(function () {
    Route::get('/', [TicketController::class, 'list'])->name('ticket.list');
    Route::get('/show/{id}', [TicketController::class, 'show'])->name('ticket.show');
    Route::get('/event/{eventId}', [TicketController::class, 'listByEvent'])->name('ticket.listByEvent');
    Route::get('/user', [TicketController::class, 'listByUser'])->name('ticket.listByUser');
    Route::get('/production/{productionId}', [TicketController::class, 'listByProduction'])->name('ticket.listByProduction');
    Route::post('/', [TicketController::class, 'store'])->name('ticket.store');
    Route::put('/{id}', [TicketController::class, 'update'])->name('ticket.update');
    Route::delete('/{id}', [TicketController::class, 'destroy'])->name('ticket.destroy');
});


/*
|--------------------------------------------------------------------------
| NOTÍCIAS
|--------------------------------------------------------------------------
*/
Route::prefix('news')->middleware('api')->group(function () {
    Route::get('/', [NewsController::class, 'list'])->name('news.list');
    Route::get('/show/{id}', [NewsController::class, 'show'])->name('news.show');
    Route::get('/search', [NewsController::class, 'search'])->name('news.search');
    Route::post('/', [NewsController::class, 'store'])->name('news.store');
    Route::post('/{id}', [NewsController::class, 'update'])->name('news.update');
    Route::delete('/{id}', [NewsController::class, 'destroy'])->name('news.destroy');
    Route::post('/{id}/comment', [NewsController::class, 'comment'])->name('news.comment');
});


/*
|--------------------------------------------------------------------------
| RELATÓRIOS E REGISTROS
|--------------------------------------------------------------------------
*/
Route::prefix('report')->middleware('api')->group(function () {
    Route::post('/order', [ReportController::class, 'order'])->name('report.order');
});

Route::prefix('service-record')->middleware('api')->group(function () {
    Route::post('/', [ServiceRecordController::class, 'store'])->name('service_record.store');
    Route::get('/listmy', [ServiceRecordController::class, 'listMy'])->name('service_record.listMy');
    Route::get('/listbyclient', [ServiceRecordController::class, 'listByClient'])->name('service_record.listByClient');
    Route::get('/listbyentity', [ServiceRecordController::class, 'listByEntity'])->name('service_record.listByEntity');
    Route::get('/listbyprovider', [ServiceRecordController::class, 'listByProvider'])->name('service_record.listByProvider');
    Route::patch('/{id}/status', [ServiceRecordController::class, 'updateStatus'])->name('service_record.updateStatus');
    Route::delete('/{id}', [ServiceRecordController::class, 'destroy'])->name('service_record.destroy');
});


/*
|--------------------------------------------------------------------------
| ESTABELECIMENTOS
|--------------------------------------------------------------------------
*/
Route::prefix('establishment')->middleware('api')->group(function () {
    Route::get('/', [EstablishmentController::class, 'list'])->name('establishment.list');
    Route::get('/category/{category}', [EstablishmentController::class, 'listByCategory'])->name('establishment.listByCategory');
    Route::get('/show/{id}', [EstablishmentController::class, 'show'])->name('establishment.show');
    Route::get('/view/{slug}', [EstablishmentController::class, 'view'])->name('establishment.view');
    Route::get('/{slug}/menu/pdf', [EstablishmentController::class, 'generatePdf'])->name('establishment.generatePdf');
});

Route::prefix('establishment')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/', [EstablishmentController::class, 'store'])->name('establishment.store');
    Route::match(['post', 'put'], '/{id}', [EstablishmentController::class, 'update'])->name('establishment.update');
    Route::delete('/{id}', [EstablishmentController::class, 'destroy'])->name('establishment.destroy');
    Route::get('/my', [EstablishmentController::class, 'myEstablishments'])->name('establishment.my');
    Route::get('/user', [EstablishmentController::class, 'listByUser'])->name('establishment.listByUser');
    Route::get('/my/category/{category}', [EstablishmentController::class, 'listMyByCategory'])->name('establishment.listMyByCategory');
});

/*
|--------------------------------------------------------------------------
| PEDIDOS E PREVISÕES
|--------------------------------------------------------------------------
*/
Route::prefix('order')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/', [OrderController::class, 'store'])->name('order.store');
    Route::get('/listbyentity', [OrderController::class, 'listByEntity'])->name('order.listByEntity');
    Route::get('/listbyemployer', [OrderController::class, 'listByEmployer'])->name('order.listByEmployer');
    Route::get('/view/{id}', [OrderController::class, 'view'])->whereNumber('id')->name('order.view'); // ✅ NOVA ROTA
    Route::get('/{id}', [OrderController::class, 'show'])->whereNumber('id')->name('order.show');
    Route::put('/{id}', [OrderController::class, 'update'])->whereNumber('id')->name('order.update');
    Route::put('/{id}/update-appointment-status', [OrderController::class, 'updateAppointmentStatus'])->whereNumber('id')->name('order.updateAppointmentStatus');
});


Route::prefix('order-forecast')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/', [OrderForecastController::class, 'index'])->name('orderForecast.index');
    Route::post('/generate', [OrderForecastController::class, 'generate'])->name('orderForecast.generate');
});


/*
|--------------------------------------------------------------------------
| ITENS
|--------------------------------------------------------------------------
*/
Route::prefix('item')->middleware('api')->group(function () {
    Route::get('/', [ItemController::class, 'listByEntity'])->name('item.listByEntity');
    Route::get('/listbyapp', [ItemController::class, 'listAll'])->name('item.listByApp');
    Route::get('/listall', [ItemController::class, 'listAll'])->name('item.listAll');
    Route::get('/listservicesbyentity', [ItemController::class, 'listServicesByEntity'])->name('item.listServicesByEntity');
    Route::get('/{id}', [ItemController::class, 'show'])->name('item.show');
    Route::get('/view/{slug}', [ItemController::class, 'view'])->name('item.view');
});

Route::prefix('item')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/', [ItemController::class, 'store'])->name('item.store');
    Route::post('/bulk', [ItemController::class, 'storeBulk'])->name('item.storeBulk');
    Route::post('/{id}', [ItemController::class, 'update'])->name('item.update');
    Route::delete('/{id}', [ItemController::class, 'destroy'])->name('item.destroy');
    Route::post('/increase-prices', [ItemController::class, 'increasePricesByPercentage'])->name('item.increasePricesByPercentage');
    Route::post('/decrease-prices', [ItemController::class, 'decreasePricesByPercentage'])->name('item.decreasePricesByPercentage');
});


/*
|--------------------------------------------------------------------------
| CARDÁPIO
|--------------------------------------------------------------------------
*/
Route::prefix('menu')->middleware('api')->group(function () {
    Route::get('/', [MenuController::class, 'list'])->name('menu.list');
    Route::get('/{id}', [MenuController::class, 'show'])->name('menu.show');
});

Route::prefix('menu')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/', [MenuController::class, 'store'])->name('menu.store');
    Route::put('/{id}', [MenuController::class, 'update'])->name('menu.update');
    Route::delete('/{id}', [MenuController::class, 'destroy'])->name('menu.destroy');
});


/*
|--------------------------------------------------------------------------
| EMPLOYER (COLABORADORES)
|--------------------------------------------------------------------------
*/
Route::prefix('employer')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/', [EmployerController::class, 'store'])->name('employer.store');
    Route::get('/list', [EmployerController::class, 'listByEstablishment'])->name('employer.list');
    Route::post('/detach', [EmployerController::class, 'detach'])->name('employer.detach');
    Route::get('/check-updates', [EmployerController::class, 'checkUpdates'])->name('employer.checkUpdates');
    Route::get('/appointments', [EmployerController::class, 'listAppointments'])->name('employer.appointments');

    // 🗓️ Horários 
    Route::get('/schedules', [EmployerController::class, 'listSchedules'])->name('employer.schedules.list');
    Route::post('/schedules', [EmployerController::class, 'saveSchedules'])->name('employer.schedules.save');
    Route::delete('/schedules/{id}', [EmployerController::class, 'deleteSchedule'])->name('employer.schedules.delete');
    Route::get('/available', [EmployerController::class, 'availableTimes'])->name('employer.availableTimes');
    Route::post('/reserve', [EmployerController::class, 'reserveSchedule'])->name('employer.reserveSchedule');
});
Route::get('/employer/view/{user_name}', [EmployerController::class, 'view'])->name('employer.view');
