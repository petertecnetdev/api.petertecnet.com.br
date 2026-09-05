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
        Route::get('/conversations/{conversationId}/messages/search', [AppMessagingController::class, 'searchMessages'])->whereNumber('conversationId')->middleware('throttle:60,1');
        Route::get('/conversations/{conversationId}/messages', [AppMessagingController::class, 'messages'])->whereNumber('conversationId')->middleware('throttle:120,1');
        Route::post('/conversations/{conversationId}/messages', [AppMessagingController::class, 'send'])->whereNumber('conversationId')->middleware('throttle:60,1');
        Route::patch('/conversations/{conversationId}/messages/{messageId}', [AppMessagingController::class, 'updateMessage'])->whereNumber(['conversationId', 'messageId'])->middleware('throttle:60,1');
        Route::delete('/conversations/{conversationId}/messages/{messageId}', [AppMessagingController::class, 'deleteMessage'])->whereNumber(['conversationId', 'messageId'])->middleware('throttle:60,1');
        Route::put('/conversations/{conversationId}/messages/{messageId}/reaction', [AppMessagingController::class, 'react'])->whereNumber(['conversationId', 'messageId'])->middleware('throttle:120,1');
        Route::get('/conversations/{conversationId}/messages/{messageId}/attachments/{attachmentId}', [AppMessagingController::class, 'attachment'])->whereNumber(['conversationId', 'messageId', 'attachmentId'])->middleware('throttle:180,1');

        Route::post('/conversations/{conversationId}/typing', [AppMessagingController::class, 'typing'])->whereNumber('conversationId')->middleware('throttle:120,1');
        Route::patch('/conversations/{conversationId}', [AppMessagingController::class, 'updateConversation'])->whereNumber('conversationId')->middleware('throttle:60,1');
        Route::patch('/conversations/{conversationId}/read', [AppMessagingController::class, 'markRead'])->whereNumber('conversationId')->middleware('throttle:120,1');

        Route::put('/people/{userId}/block', [AppMessagingController::class, 'block'])->whereNumber('userId')->middleware('throttle:30,1');
        Route::delete('/people/{userId}/block', [AppMessagingController::class, 'unblock'])->whereNumber('userId')->middleware('throttle:30,1');
        Route::post('/people/{userId}/report', [AppMessagingController::class, 'report'])->whereNumber('userId')->middleware('throttle:20,1');
    });
