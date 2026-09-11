<?php

use App\Domain\Messaging\Http\Controllers\MessagingController;
use Illuminate\Support\Facades\Route;

Route::prefix('messaging')
    ->middleware(['api', 'auth:api', 'token.version', 'app.context'])
    ->group(function () {
        Route::get('/conversations', [MessagingController::class, 'index'])->middleware('throttle:240,1');
        Route::get('/people', [MessagingController::class, 'people'])->middleware('throttle:120,1');
        Route::post('/direct', [MessagingController::class, 'openDirect'])->middleware('throttle:60,1');
        Route::get('/conversations/{conversationId}', [MessagingController::class, 'showConversation'])->whereNumber('conversationId');
        Route::get('/conversations/{conversationId}/messages', [MessagingController::class, 'messages'])->whereNumber('conversationId')->middleware('throttle:240,1');
        Route::post('/conversations/{conversationId}/messages', [MessagingController::class, 'send'])->whereNumber('conversationId')->middleware('throttle:90,1');
        Route::post('/conversations/{conversationId}/delivered', [MessagingController::class, 'markDelivered'])->whereNumber('conversationId')->middleware('throttle:240,1');
        Route::post('/conversations/{conversationId}/read', [MessagingController::class, 'markRead'])->whereNumber('conversationId')->middleware('throttle:240,1');
        Route::patch('/conversations/{conversationId}/state', [MessagingController::class, 'state'])->whereNumber('conversationId')->middleware('throttle:120,1');
        Route::delete('/conversations/{conversationId}', [MessagingController::class, 'archive'])->whereNumber('conversationId')->middleware('throttle:60,1');
        Route::patch('/messages/{messageId}', [MessagingController::class, 'updateMessage'])->whereNumber('messageId')->middleware('throttle:90,1');
        Route::delete('/messages/{messageId}', [MessagingController::class, 'deleteMessage'])->whereNumber('messageId')->middleware('throttle:60,1');
        Route::post('/messages/{messageId}/reaction', [MessagingController::class, 'reaction'])->whereNumber('messageId')->middleware('throttle:180,1');
    });
