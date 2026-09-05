<?php

use App\Domain\Messaging\Http\Controllers\AppMessagingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}/messaging')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:social'])
    ->group(function () {
        Route::get('/conversations', [AppMessagingController::class, 'index'])->middleware('throttle:120,1');
        Route::get('/unread-count', [AppMessagingController::class, 'unreadCount'])->middleware('throttle:120,1');
        Route::get('/people', [AppMessagingController::class, 'people'])->middleware('throttle:120,1');
        Route::post('/conversations/direct', [AppMessagingController::class, 'createDirect'])->middleware('throttle:30,1');
        Route::get('/conversations/{conversationId}/messages', [AppMessagingController::class, 'messages'])->whereNumber('conversationId')->middleware('throttle:120,1');
        Route::post('/conversations/{conversationId}/messages', [AppMessagingController::class, 'send'])->whereNumber('conversationId')->middleware('throttle:60,1');
        Route::patch('/conversations/{conversationId}/read', [AppMessagingController::class, 'markRead'])->whereNumber('conversationId')->middleware('throttle:120,1');
    });
