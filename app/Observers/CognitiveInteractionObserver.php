<?php

namespace App\Observers;

use App\Jobs\LearnFromInteraction;
use App\Models\Interaction;
use Illuminate\Support\Facades\Log;

class CognitiveInteractionObserver
{
    public function created(Interaction $interaction): void
    {
        if (! config('cognition.enabled') || ! config('cognition.auto_learn', true)) return;
        try {
            LearnFromInteraction::dispatch($interaction->id)->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('Falha ao enfileirar aprendizado cognitivo.', ['interaction_id'=>$interaction->id,'message'=>$e->getMessage()]);
        }
    }
}
