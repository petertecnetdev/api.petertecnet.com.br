<?php

use App\Domain\CRM\Http\Controllers\PublicInquiryController;
use App\Domain\Discovery\Http\Controllers\ContentController;
use App\Domain\Discovery\Http\Controllers\ContentManagementController;
use App\Domain\Discovery\Http\Controllers\DiscoveryAnalyticsController;
use App\Domain\Discovery\Http\Controllers\DiscoveryController;
use App\Domain\Discovery\Http\Controllers\DiscoveryEventController;
use App\Domain\Discovery\Http\Controllers\DiscoveryGrowthController;
use App\Domain\Discovery\Http\Controllers\DiscoveryLearningController;
use App\Domain\Discovery\Http\Controllers\DiscoverySearchController;
use App\Domain\Discovery\Http\Controllers\OptimizedMediaController;
use App\Domain\Discovery\Http\Controllers\SeoDiagnosticsController;
use App\Domain\Discovery\Http\Controllers\SitemapController;
use App\Domain\Discovery\Http\Controllers\SocialCardController;
use App\Domain\Discovery\Http\Controllers\WebVitalAnalyticsController;
use App\Domain\Discovery\Http\Controllers\WebVitalController;
use App\Domain\Messaging\Http\Controllers\MessagingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/content', [ContentController::class, 'index'])->middleware('throttle:120,1');
    Route::get('/content/{slug}', [ContentController::class, 'show'])->where('slug', '[A-Za-z0-9\-]+')->middleware('throttle:120,1');

    Route::prefix('apps/{application}')
        ->middleware(['app.context', 'app.capability:crm'])
        ->group(function () {
            Route::post('/crm/inquiries', [PublicInquiryController::class, 'store'])->middleware('throttle:12,1');
        });

    Route::prefix('apps/{application}')
        ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:social'])
        ->group(function () {
            Route::get('/messaging/conversations', [MessagingController::class, 'index'])->middleware('throttle:180,1');
            Route::get('/messaging/people', [MessagingController::class, 'people'])->middleware('throttle:120,1');
            Route::post('/messaging/direct', [MessagingController::class, 'openDirect'])->middleware('throttle:60,1');
            Route::post('/messaging/groups', [MessagingController::class, 'createGroup'])->middleware('throttle:30,1');
            Route::get('/messaging/settings', [MessagingController::class, 'settings'])->middleware('throttle:120,1');
            Route::put('/messaging/settings', [MessagingController::class, 'updateSettings'])->middleware('throttle:30,1');
            Route::get('/messaging/push-key', [MessagingController::class, 'pushPublicKey'])->middleware('throttle:120,1');
            Route::post('/messaging/push-subscriptions', [MessagingController::class, 'subscribePush'])->middleware('throttle:30,1');
            Route::delete('/messaging/push-subscriptions', [MessagingController::class, 'unsubscribePush'])->middleware('throttle:30,1');
            Route::post('/messaging/engagement/click', [MessagingController::class, 'engagementClick'])->middleware('throttle:60,1');
            Route::get('/messaging/metrics', [MessagingController::class, 'metrics'])->middleware('throttle:60,1');
            Route::post('/messaging/presence/heartbeat', [MessagingController::class, 'heartbeat'])->middleware('throttle:120,1');
            Route::get('/messaging/presence/{userId}', [MessagingController::class, 'presence'])->whereNumber('userId')->middleware('throttle:180,1');
            Route::post('/messaging/users/{userId}/block', [MessagingController::class, 'block'])->whereNumber('userId')->middleware('throttle:30,1');
            Route::delete('/messaging/users/{userId}/block', [MessagingController::class, 'unblock'])->whereNumber('userId')->middleware('throttle:30,1');
            Route::get('/messaging/attachments/{attachmentId}', [MessagingController::class, 'attachment'])->whereNumber('attachmentId')->middleware('throttle:240,1');

            Route::get('/messaging/conversations/{conversationId}', [MessagingController::class, 'showConversation'])->whereNumber('conversationId')->middleware('throttle:180,1');
            Route::patch('/messaging/conversations/{conversationId}', [MessagingController::class, 'updateConversation'])->whereNumber('conversationId')->middleware('throttle:60,1');
            Route::post('/messaging/conversations/{conversationId}/accept-request', [MessagingController::class, 'acceptRequest'])->whereNumber('conversationId')->middleware('throttle:30,1');
            Route::delete('/messaging/conversations/{conversationId}/request', [MessagingController::class, 'rejectRequest'])->whereNumber('conversationId')->middleware('throttle:30,1');
            Route::delete('/messaging/conversations/{conversationId}', [MessagingController::class, 'archive'])->whereNumber('conversationId')->middleware('throttle:60,1');
            Route::post('/messaging/conversations/{conversationId}/participants', [MessagingController::class, 'addParticipants'])->whereNumber('conversationId')->middleware('throttle:30,1');
            Route::delete('/messaging/conversations/{conversationId}/participants/{userId}', [MessagingController::class, 'removeParticipant'])->whereNumber('conversationId')->whereNumber('userId')->middleware('throttle:30,1');
            Route::get('/messaging/conversations/{conversationId}/messages', [MessagingController::class, 'messages'])->whereNumber('conversationId')->middleware('throttle:240,1');
            Route::get('/messaging/conversations/{conversationId}/scheduled', [MessagingController::class, 'scheduledMessages'])->whereNumber('conversationId')->middleware('throttle:120,1');
            Route::post('/messaging/conversations/{conversationId}/messages', [MessagingController::class, 'send'])->whereNumber('conversationId')->middleware('throttle:120,1');
            Route::patch('/messaging/conversations/{conversationId}/messages/{messageId}', [MessagingController::class, 'editMessage'])->whereNumber('conversationId')->whereNumber('messageId')->middleware('throttle:60,1');
            Route::delete('/messaging/conversations/{conversationId}/messages/{messageId}', [MessagingController::class, 'deleteMessage'])->whereNumber('conversationId')->whereNumber('messageId')->middleware('throttle:60,1');
            Route::delete('/messaging/conversations/{conversationId}/scheduled/{messageId}', [MessagingController::class, 'cancelScheduledMessage'])->whereNumber('conversationId')->whereNumber('messageId')->middleware('throttle:60,1');
            Route::post('/messaging/conversations/{conversationId}/messages/{messageId}/reactions', [MessagingController::class, 'react'])->whereNumber('conversationId')->whereNumber('messageId')->middleware('throttle:120,1');
            Route::delete('/messaging/conversations/{conversationId}/messages/{messageId}/reactions', [MessagingController::class, 'removeReaction'])->whereNumber('conversationId')->whereNumber('messageId')->middleware('throttle:120,1');
            Route::put('/messaging/conversations/{conversationId}/messages/{messageId}/pin', [MessagingController::class, 'pinMessage'])->whereNumber('conversationId')->whereNumber('messageId')->middleware('throttle:60,1');
            Route::post('/messaging/conversations/{conversationId}/read', [MessagingController::class, 'markRead'])->whereNumber('conversationId')->middleware('throttle:180,1');
            Route::post('/messaging/conversations/{conversationId}/typing', [MessagingController::class, 'typing'])->whereNumber('conversationId')->middleware('throttle:240,1');
            Route::post('/messaging/conversations/{conversationId}/activity', [MessagingController::class, 'conversationActivity'])->whereNumber('conversationId')->middleware('throttle:240,1');
            Route::post('/messaging/conversations/{conversationId}/reports', [MessagingController::class, 'report'])->whereNumber('conversationId')->middleware('throttle:20,1');
            Route::post('/messaging/conversations/{conversationId}/calls', [MessagingController::class, 'startCall'])->whereNumber('conversationId')->middleware('throttle:30,1');
            Route::patch('/messaging/conversations/{conversationId}/calls/{callId}', [MessagingController::class, 'updateCall'])->whereNumber('conversationId')->whereNumber('callId')->middleware('throttle:60,1');
        });

    Route::prefix('discovery')->group(function () {
        Route::get('/sitemap.xml', [SitemapController::class, 'show'])->middleware('throttle:30,1');
        Route::get('/search', [DiscoverySearchController::class, 'search'])->middleware('throttle:120,1');
        Route::get('/ranked-search', [DiscoveryLearningController::class, 'search'])->middleware('throttle:120,1');
        Route::get('/recommendations', [DiscoveryLearningController::class, 'recommendations'])->middleware('throttle:120,1');
        Route::get('/experiments/resolve', [DiscoveryLearningController::class, 'experiment'])->middleware('throttle:180,1');
        Route::post('/experiments/events', [DiscoveryLearningController::class, 'experimentEvent'])->middleware('throttle:240,1');
        Route::post('/accessibility', [DiscoveryLearningController::class, 'accessibility'])->middleware('throttle:60,1');
        Route::get('/landing', [DiscoverySearchController::class, 'landing'])->middleware('throttle:120,1');
        Route::get('/landing-candidates', [DiscoverySearchController::class, 'candidates'])->middleware('throttle:60,1');
        Route::get('/categories', [DiscoveryController::class, 'categories'])->middleware('throttle:120,1');
        Route::get('/categories/{slug}', [DiscoveryController::class, 'category'])->where('slug', '[A-Za-z0-9\-]+')->middleware('throttle:120,1');
        Route::get('/establishments/{identifier}', [DiscoveryController::class, 'establishment'])->where('identifier', '[A-Za-z0-9\-]+')->middleware('throttle:120,1');
        Route::get('/items/{identifier}', [DiscoveryController::class, 'item'])->where('identifier', '[A-Za-z0-9\-]+')->middleware('throttle:120,1');
        Route::get('/media/capabilities', [OptimizedMediaController::class, 'capabilities'])->middleware('throttle:120,1');
        Route::get('/media/{uuid}', [OptimizedMediaController::class, 'show'])->whereUuid('uuid')->middleware('throttle:240,1');
        Route::get('/social-card/{type}/{identifier}.png', [SocialCardController::class, 'show'])
            ->where('type', 'content|establishment|item|category')
            ->where('identifier', '[A-Za-z0-9\-]+')
            ->middleware('throttle:120,1');
        Route::post('/events', [DiscoveryEventController::class, 'store'])->middleware('throttle:180,1');
        Route::post('/web-vitals', [WebVitalController::class, 'store'])->middleware('throttle:240,1');
    });
});

Route::middleware(['auth:api', 'token.version', \App\Http\Middleware\PeterTecnetAdminApi::class])->prefix('admin')->group(function () {
    Route::get('/content', [ContentManagementController::class, 'index']);
    Route::post('/content', [ContentManagementController::class, 'store']);
    Route::patch('/content/{content}', [ContentManagementController::class, 'update'])->whereNumber('content');
    Route::post('/content/{content}/publish', [ContentManagementController::class, 'publish'])->whereNumber('content');
    Route::delete('/content/{content}', [ContentManagementController::class, 'destroy'])->whereNumber('content');
    Route::get('/discovery/analytics', [DiscoveryAnalyticsController::class, 'summary']);
    Route::get('/discovery/web-vitals', [WebVitalAnalyticsController::class, 'index']);
    Route::get('/discovery/seo-diagnostics', [SeoDiagnosticsController::class, 'index']);
    Route::get('/discovery/growth', [DiscoveryGrowthController::class, 'overview']);
    Route::post('/discovery/growth/search-performance/sync', [DiscoveryGrowthController::class, 'syncSearchPerformance']);
    Route::post('/discovery/growth/search-performance/import', [DiscoveryGrowthController::class, 'importSearchPerformance']);
    Route::post('/discovery/growth/search-index/rebuild', [DiscoveryGrowthController::class, 'rebuildIndex']);
    Route::post('/discovery/growth/monitor', [DiscoveryGrowthController::class, 'monitor']);
    Route::post('/discovery/growth/experiments', [DiscoveryGrowthController::class, 'storeExperiment']);
    Route::patch('/discovery/growth/experiments/{experiment}', [DiscoveryGrowthController::class, 'updateExperiment'])->whereNumber('experiment');
});

require __DIR__.'/creative.php';
