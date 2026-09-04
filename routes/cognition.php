<?php

use App\Http\Controllers\Cognition\CognitiveAgentController;
use App\Http\Controllers\Cognition\CognitiveLearningController;
use App\Http\Controllers\Cognition\CognitiveResearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('cognition')->middleware(['auth:api','throttle:60,1'])->group(function () {
    Route::get('/agents',[CognitiveAgentController::class,'index']);
    Route::get('/agents/default',[CognitiveAgentController::class,'default']);
    Route::post('/agents',[CognitiveAgentController::class,'store']);
    Route::get('/agents/{agent}',[CognitiveAgentController::class,'show']);
    Route::put('/agents/{agent}',[CognitiveAgentController::class,'update']);
    Route::post('/agents/{agent}/state',[CognitiveAgentController::class,'state']);
    Route::get('/agents/{agent}/observations',[CognitiveLearningController::class,'observations']);
    Route::get('/agents/{agent}/memories',[CognitiveLearningController::class,'memories']);
    Route::get('/agents/{agent}/beliefs',[CognitiveLearningController::class,'beliefs']);
    Route::get('/agents/{agent}/goals',[CognitiveLearningController::class,'goals']);
    Route::get('/agents/{agent}/learning-events',[CognitiveLearningController::class,'learningEvents']);
    Route::post('/agents/{agent}/observations',[CognitiveLearningController::class,'observe']);
    Route::post('/agents/{agent}/feedback',[CognitiveLearningController::class,'feedback']);
    Route::post('/agents/{agent}/goals',[CognitiveLearningController::class,'storeGoal']);
    Route::patch('/agents/{agent}/beliefs/{belief}/retract',[CognitiveLearningController::class,'retractBelief']);
    Route::get('/research/framework',[CognitiveResearchController::class,'framework']);
    Route::post('/research/bootstrap',[CognitiveResearchController::class,'bootstrap']);
    Route::get('/research/experiments',[CognitiveResearchController::class,'experiments']);
    Route::get('/agents/{agent}/research/runs',[CognitiveResearchController::class,'runs']);
    Route::post('/agents/{agent}/research/experiments/{experiment}/runs',[CognitiveResearchController::class,'recordRun']);
});
