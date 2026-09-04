<?php

namespace App\Jobs;

use App\Models\Interaction;
use App\Services\CognitiveLearningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class LearnFromInteraction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(public int $interactionId) { $this->onQueue((string)config('cognition.queue','default')); }

    public function handle(CognitiveLearningService $learning): void
    {
        $interaction = Interaction::find($this->interactionId);
        if (! $interaction) return;
        try { $learning->observeInteraction($interaction); }
        catch (\Throwable $e) { Log::warning('Aprendizado cognitivo falhou sem interromper a aplicação.', ['interaction_id'=>$this->interactionId,'message'=>$e->getMessage()]); }
    }
}
