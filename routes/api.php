<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ApplicationController as AdminApplicationController;
use App\Http\Controllers\{
    ApplicationController,
    AuthController,
    EmployerController,
    EstablishmentController,
    EventController,
    FileController,
    HomeController,
    ItemController,
    MenuController,
    NewsController,
    OrderController,
    OrderForecastController,
    ProductionController,
    ProfileController,
    ReportController,
    ServiceRecordController,
    TicketController,
    UserController
};

/*
|--------------------------------------------------------------------------
| HOME / AUTH
|--------------------------------------------------------------------------
*/
Route::prefix('home')->middleware('api')->group(function () {
    Route::get('/{app_id}', [HomeController::class, 'home'])->whereNumber('app_id')->name('home.main');
});

Route::prefix('auth')->middleware('api')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/password-email', [AuthController::class, 'sendResetCodeEmail'])->middleware('throttle:5,1');
    Route::post('/password-reset', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:30,1');
    Route::post('/google', [AuthController::class, 'googleAuth'])->middleware('throttle:10,1')->name('auth.google');

    Route::middleware('auth:api')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/check-auth', [AuthController::class, 'checkauth']);
        Route::post('/email-verify', [AuthController::class, 'emailVerify']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::post('/resend-code-email-verification', [AuthController::class, 'resendCodeEmailVerification'])
            ->middleware('throttle:5,1');
    });
});

Route::post('/invite', [AuthController::class, 'invite'])->middleware(['api', 'throttle:10,1'])->name('invite');
Route::post('/invite-complete', [AuthController::class, 'completeInvite'])->middleware(['api', 'throttle:10,1'])->name('invite.complete');

/*
|--------------------------------------------------------------------------
| USERS / PROFILES
|--------------------------------------------------------------------------
*/
Route::prefix('user')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/', [UserController::class, 'list'])->name('user.list');
    Route::get('/search', [UserController::class, 'search'])->name('user.search');
    Route::post('/find-for-employer', [UserController::class, 'findForEmployer'])->name('user.findForEmployer');
    Route::post('/find-for-order', [UserController::class, 'findForOrder'])->name('user.findForOrder');
    Route::get('/show/{id}', [UserController::class, 'show'])->whereNumber('id')->name('user.show');
    Route::post('/new', [UserController::class, 'store'])->name('user.store');
    Route::post('/{user}', [UserController::class, 'update'])->whereNumber('user')->name('user.update');
    Route::delete('/{id}', [UserController::class, 'destroy'])->whereNumber('id')->name('user.destroy');
    Route::get('/{userName}', [UserController::class, 'view'])->name('user.view');
});

Route::prefix('profile')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/', [ProfileController::class, 'list'])->name('profile.list');
    Route::get('/{id}', [ProfileController::class, 'show'])->whereNumber('id')->name('profile.show');
    Route::post('/', [ProfileController::class, 'store'])->name('profile.store');
    Route::put('/{id}', [ProfileController::class, 'update'])->whereNumber('id')->name('profile.update');
    Route::delete('/{id}', [ProfileController::class, 'destroy'])->whereNumber('id')->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| PRODUCTIONS
|--------------------------------------------------------------------------
*/
Route::prefix('production')->middleware('api')->group(function () {
    Route::get('/', [ProductionController::class, 'list'])->name('production.list');
    Route::get('/show/{id}', [ProductionController::class, 'show'])->whereNumber('id')->name('production.show');
    Route::get('/cnpj/get-company-info', [ProductionController::class, 'getCompanyInfo'])
        ->middleware('throttle:30,1')
        ->name('production.getCompanyInfo');
    Route::get('/{slug}', [ProductionController::class, 'view'])
        ->where('slug', '[A-Za-z0-9\\-]+')
        ->name('production.view');
});

Route::prefix('production')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/', [ProductionController::class, 'store'])->name('production.store');
    Route::post('/{id}', [ProductionController::class, 'update'])->whereNumber('id')->name('production.update');
    Route::put('/{id}', [ProductionController::class, 'update'])->whereNumber('id')->name('production.update.put');
    Route::delete('/{id}', [ProductionController::class, 'delete'])->whereNumber('id')->name('production.delete');
});

/*
|--------------------------------------------------------------------------
| EVENTS
|--------------------------------------------------------------------------
*/
Route::prefix('event')->middleware('api')->group(function () {
    Route::get('/', [EventController::class, 'list'])->name('event.list');
    Route::get('/show/{id}', [EventController::class, 'show'])->whereNumber('id')->name('event.show');
});

Route::prefix('event')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/myevents/list', [EventController::class, 'myEvents'])->name('event.myevents');
    Route::post('/', [EventController::class, 'store'])->name('event.store');
    Route::post('/{id}', [EventController::class, 'update'])->whereNumber('id')->name('event.update');
    Route::put('/{id}', [EventController::class, 'update'])->whereNumber('id')->name('event.update.put');
    Route::delete('/{id}', [EventController::class, 'delete'])->whereNumber('id')->name('event.delete');
});

Route::prefix('event')->middleware('api')->group(function () {
    Route::get('/{slug}', [EventController::class, 'view'])
        ->where('slug', '[A-Za-z0-9\\-]+')
        ->name('event.view');
});

/*
|--------------------------------------------------------------------------
| TICKETS
|--------------------------------------------------------------------------
*/
Route::prefix('ticket')->middleware('api')->group(function () {
    Route::get('/', [TicketController::class, 'list'])->name('ticket.list');
    Route::get('/show/{id}', [TicketController::class, 'show'])->whereNumber('id')->name('ticket.show');
    Route::get('/event/{eventId}', [TicketController::class, 'listByEvent'])->whereNumber('eventId')->name('ticket.listByEvent');
});

Route::prefix('ticket')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/user', [TicketController::class, 'listByUser'])->name('ticket.listByUser');
    Route::get('/production/{productionId}', [TicketController::class, 'listByProduction'])->whereNumber('productionId')->name('ticket.listByProduction');
    Route::post('/', [TicketController::class, 'store'])->name('ticket.store');
    Route::put('/{id}', [TicketController::class, 'update'])->whereNumber('id')->name('ticket.update');
    Route::post('/{id}', [TicketController::class, 'update'])->whereNumber('id')->name('ticket.update.post');
    Route::delete('/{id}', [TicketController::class, 'destroy'])->whereNumber('id')->name('ticket.destroy');
});

/*
|--------------------------------------------------------------------------
| NEWS
|--------------------------------------------------------------------------
*/
Route::prefix('news')->middleware('api')->group(function () {
    Route::get('/', [NewsController::class, 'list'])->name('news.list');
    Route::get('/search', [NewsController::class, 'search'])->name('news.search');
    Route::get('/show/{id}', [NewsController::class, 'show'])->whereNumber('id')->name('news.show');
});

Route::prefix('news')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/', [NewsController::class, 'store'])->name('news.store');
    Route::post('/{id}', [NewsController::class, 'update'])->whereNumber('id')->name('news.update');
    Route::put('/{id}', [NewsController::class, 'update'])->whereNumber('id')->name('news.update.put');
    Route::delete('/{id}', [NewsController::class, 'destroy'])->whereNumber('id')->name('news.destroy');
    Route::post('/{id}/comment', [NewsController::class, 'comment'])->whereNumber('id')->name('news.comment');
});

/*
|--------------------------------------------------------------------------
| REPORTS / SERVICE RECORDS
|--------------------------------------------------------------------------
*/
Route::prefix('report')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/order', [ReportController::class, 'order'])->name('report.order');
});

Route::prefix('service-record')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/', [ServiceRecordController::class, 'store'])->name('service_record.store');
    Route::get('/listmy', [ServiceRecordController::class, 'listMy'])->name('service_record.listMy');
    Route::get('/listbyclient', [ServiceRecordController::class, 'listByClient'])->name('service_record.listByClient');
    Route::get('/listbyentity', [ServiceRecordController::class, 'listByEntity'])->name('service_record.listByEntity');
    Route::get('/listbyprovider', [ServiceRecordController::class, 'listByProvider'])->name('service_record.listByProvider');
    Route::patch('/{id}/status', [ServiceRecordController::class, 'updateStatus'])->whereNumber('id')->name('service_record.updateStatus');
    Route::delete('/{id}', [ServiceRecordController::class, 'destroy'])->whereNumber('id')->name('service_record.destroy');
});

/*
|--------------------------------------------------------------------------
| ESTABLISHMENTS
|--------------------------------------------------------------------------
*/
Route::prefix('establishment')->middleware('api')->group(function () {
    Route::get('/', [EstablishmentController::class, 'list'])->name('establishment.list');
    Route::get('/category/{category}', [EstablishmentController::class, 'listByCategory'])->name('establishment.listByCategory');
    Route::get('/show/{id}', [EstablishmentController::class, 'show'])->whereNumber('id')->name('establishment.show');
    Route::get('/cities/{app_id}', [EstablishmentController::class, 'listCities'])->whereNumber('app_id');
    Route::get('/view/{slug}', [EstablishmentController::class, 'view'])->name('establishment.view');
    Route::get('/{slug}/menu/pdf', [EstablishmentController::class, 'generatePdf'])->name('establishment.generatePdf');
    Route::get('/list-others/{slug}', [EstablishmentController::class, 'listOthers'])
        ->where('slug', '[A-Za-z0-9\\-]+')
        ->name('establishment.listOthers');
    Route::get('/home/{app_id}', [EstablishmentController::class, 'home'])->whereNumber('app_id')->name('establishment.home');
});

Route::prefix('establishment')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/', [EstablishmentController::class, 'store'])->name('establishment.store');
    Route::match(['post', 'put'], '/{id}', [EstablishmentController::class, 'update'])->whereNumber('id')->name('establishment.update');
    Route::delete('/{id}', [EstablishmentController::class, 'destroy'])->whereNumber('id')->name('establishment.destroy');
    Route::get('/my', [EstablishmentController::class, 'myEstablishments'])->name('establishment.my');
    Route::get('/user', [EstablishmentController::class, 'listByUser'])->name('establishment.listByUser');
    Route::get('/my/category/{category}', [EstablishmentController::class, 'listMyByCategory'])->name('establishment.listMyByCategory');
    Route::post('/my/app', [EstablishmentController::class, 'listMyByApp'])->name('establishment.listMyByApp');
});

/*
|--------------------------------------------------------------------------
| ORDERS / FORECASTS
|--------------------------------------------------------------------------
*/
Route::prefix('order')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/', [OrderController::class, 'store'])->name('order.store');
    Route::post('/direct', [OrderController::class, 'storeDirect'])->name('order.storeDirect');
    Route::get('/list-by-client/{app_id}', [OrderController::class, 'listByClient'])->whereNumber('app_id')->name('order.listByClient');
    Route::get('/list-by-entity-slug/{slug}', [OrderController::class, 'listByEntitySlug'])->name('order.listByEntitySlug');
    Route::get('/listbyemployer', [OrderController::class, 'listByEmployer'])->name('order.listByEmployer');
    Route::get('/view/{id}', [OrderController::class, 'view'])->whereNumber('id')->name('order.view');
    Route::get('/listmy/{app_id}', [OrderController::class, 'listMy'])->whereNumber('app_id')->name('order.listMy');
    Route::put('/{id}/update-status', [OrderController::class, 'updateOrderStatus'])->whereNumber('id')->name('order.updateOrderStatus');
    Route::put('/{id}', [OrderController::class, 'update'])->whereNumber('id')->name('order.update');
    Route::get('/{id}', [OrderController::class, 'show'])->whereNumber('id')->name('order.show');
});

Route::prefix('order-forecast')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/', [OrderForecastController::class, 'index'])->name('orderForecast.index');
    Route::post('/generate', [OrderForecastController::class, 'generate'])->middleware('throttle:20,1')->name('orderForecast.generate');
});

/*
|--------------------------------------------------------------------------
| ITEMS
|--------------------------------------------------------------------------
*/
Route::prefix('item')->middleware('api')->group(function () {
    Route::get('/list-by-entity/{identifier}', [ItemController::class, 'listByEntity'])->name('item.listByEntity');
    Route::get('/list-others/{slug}', [ItemController::class, 'listOthers'])->where('slug', '[A-Za-z0-9\\-]+')->name('item.listOthers');
    Route::get('/index', [ItemController::class, 'index'])->name('item.index');
    Route::get('/listbyapp/{app_id}', [ItemController::class, 'listByApp'])->whereNumber('app_id')->name('item.listByApp');
    Route::get('/listservicesbyentity', [ItemController::class, 'listServicesByEntity'])->name('item.listServicesByEntity');
    Route::get('/view/{slug}', [ItemController::class, 'view'])->where('slug', '[A-Za-z0-9\\-]+')->name('item.view');
    Route::get('/home/{app_id}', [ItemController::class, 'home'])->whereNumber('app_id')->name('item.home');
    Route::get('/{id}', [ItemController::class, 'show'])->whereNumber('id')->name('item.show');
});

Route::prefix('item')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/listall', [ItemController::class, 'listAll'])->name('item.listAll');
    Route::post('/', [ItemController::class, 'store'])->name('item.store');
    Route::post('/bulk', [ItemController::class, 'storeBulk'])->name('item.storeBulk');
    Route::post('/increase-prices', [ItemController::class, 'increasePricesByPercentage'])->name('item.increasePricesByPercentage');
    Route::post('/decrease-prices', [ItemController::class, 'decreasePricesByPercentage'])->name('item.decreasePricesByPercentage');
    Route::post('/{id}', [ItemController::class, 'update'])->whereNumber('id')->name('item.update');
    Route::put('/{id}', [ItemController::class, 'update'])->whereNumber('id')->name('item.update.put');
    Route::delete('/{id}', [ItemController::class, 'destroy'])->whereNumber('id')->name('item.destroy');
});

/*
|--------------------------------------------------------------------------
| MENU / EMPLOYERS / FILES
|--------------------------------------------------------------------------
*/
Route::prefix('menu')->middleware('api')->group(function () {
    Route::get('/', [MenuController::class, 'list'])->name('menu.list');
    Route::get('/{id}', [MenuController::class, 'show'])->whereNumber('id')->name('menu.show');
});

Route::prefix('menu')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/', [MenuController::class, 'store'])->name('menu.store');
    Route::put('/{id}', [MenuController::class, 'update'])->whereNumber('id')->name('menu.update');
    Route::delete('/{id}', [MenuController::class, 'destroy'])->whereNumber('id')->name('menu.destroy');
});

Route::prefix('employer')->middleware('api')->group(function () {
    Route::get('/view/{user_name}', [EmployerController::class, 'view'])->name('employer.view');
    Route::get('/home/{app_id}', [EmployerController::class, 'home'])->whereNumber('app_id')->name('employer.home');
    Route::get('/list-by-entity/{identifier}', [EmployerController::class, 'listByEntity'])->name('employer.listByEntity');
    Route::get('/list-by-item/{identifier}', [EmployerController::class, 'listByItem'])->name('employer.listByItem');
    Route::get('/list-others/{identifier}', [EmployerController::class, 'listOthers'])->name('employer.listOthers');
});

Route::prefix('employer')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/store', [EmployerController::class, 'store'])->name('employer.store');
    Route::post('/detach', [EmployerController::class, 'detach'])->name('employer.detach');
    Route::post('/list-schedules', [EmployerController::class, 'listSchedules'])->name('employer.schedules.list');
    Route::post('/save-schedules', [EmployerController::class, 'saveSchedules'])->name('employer.schedules.save');
    Route::delete('/delete-schedule/{id}', [EmployerController::class, 'deleteSchedule'])->whereNumber('id')->name('employer.schedules.delete');
    Route::post('/available-times', [EmployerController::class, 'availableTimes'])->name('employer.availableTimes');
    Route::post('/reserve-schedule', [EmployerController::class, 'reserveSchedule'])->name('employer.reserveSchedule');
    Route::post('/list-appointments', [EmployerController::class, 'listAppointments'])->name('employer.listAppointments');
    Route::post('/list-my-orders', [EmployerController::class, 'listMyOrders'])->name('employer.listMyOrders');
    Route::post('/check-updates', [EmployerController::class, 'checkUpdates'])->name('employer.checkUpdates');
});

Route::prefix('file')->middleware('api')->group(function () {
    Route::get('/view/{slug}', [FileController::class, 'view'])->name('file.view');
    Route::get('/download/{id}', [FileController::class, 'download'])->whereNumber('id')->name('file.download');
    Route::get('/list-by-entity', [FileController::class, 'listByEntity'])->name('file.listByEntity');
});

Route::prefix('file')->middleware(['api', 'auth:api'])->group(function () {
    Route::post('/', [FileController::class, 'store'])->name('file.store');
    Route::put('/{id}', [FileController::class, 'update'])->whereNumber('id')->name('file.update');
    Route::post('/{id}', [FileController::class, 'update'])->whereNumber('id')->name('file.update.post');
    Route::delete('/{id}', [FileController::class, 'delete'])->whereNumber('id')->name('file.delete');
});

/*
|--------------------------------------------------------------------------
| APPLICATIONS
|--------------------------------------------------------------------------
*/
Route::prefix('applications')->middleware('api')->group(function () {
    Route::get('/', [ApplicationController::class, 'index'])->name('applications.index');
    Route::get('/{slug}', [ApplicationController::class, 'show'])
        ->where('slug', '[A-Za-z0-9\\-]+')
        ->name('applications.show');
});

Route::prefix('admin/applications')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/', [AdminApplicationController::class, 'index']);
    Route::post('/', [AdminApplicationController::class, 'store']);
    Route::put('/{application}', [AdminApplicationController::class, 'update'])->whereNumber('application');
    Route::delete('/{application}', [AdminApplicationController::class, 'destroy'])->whereNumber('application');
});
