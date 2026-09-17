<?php

use App\Http\Controllers\CommunicationTrackingController;
use Illuminate\Support\Facades\Route;

Route::get('/communications/track/click/{communicationId}', [CommunicationTrackingController::class, 'click'])
    ->whereUuid('communicationId')
    ->middleware(['signed', 'throttle:120,1'])
    ->name('communications.track.click');

Route::get('/communications/track/open/{communicationId}.gif', [CommunicationTrackingController::class, 'open'])
    ->whereUuid('communicationId')
    ->middleware(['signed', 'throttle:240,1'])
    ->name('communications.track.open');
