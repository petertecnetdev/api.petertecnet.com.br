<?php

namespace App\Http\Controllers\Cognition;

use App\Models\CognitiveAgent;
use App\Models\CognitiveBelief;
use App\Services\CognitiveLearningService;
use App\Services\CognitiveQueryService;
use Illuminate\Http\Request;

class CognitiveLearningController extends CognitionController
{
    public function observations(Request $request, CognitiveAgent $agent, CognitiveQueryService $queries)
    {
        $this->authorizeCognition($request);
        return response()->json($queries->paginateObservations($agent, (int) $request->integer('per_page', 30)));
    }

    public function memories(Request $request, CognitiveAgent $agent, CognitiveQueryService $queries)
    {
        $this->authorizeCognition($request);
        return response()->json($queries->paginateMemories(
            $agent,
            (int) $request->integer('per_page', 30),
            $request->filled('type') ? (string) $request->string('type') : null,
            $request->boolean('include_inactive')
        ));
    }

    public function beliefs(Request $request, CognitiveAgent $agent, CognitiveQueryService $queries)
    {
        $this->authorizeCognition($request);
        return response()->json($queries->paginateBeliefs(
            $agent,
            (int) $request->integer('per_page', 30),
            $request->filled('status') ? (string) $request->string('status') : null
        ));
    }

    public function goals(Request $request, CognitiveAgent $agent, CognitiveQueryService $queries)
    {
        $this->authorizeCognition($request);
        return response()->json($queries->paginateGoals($agent, (int) $request->integer('per_page', 50)));
    }

    public function learningEvents(Request $request, CognitiveAgent $agent, CognitiveQueryService $queries)
    {
        $this->authorizeCognition($request);
        return response()->json($queries->paginateLearningEvents($agent, (int) $request->integer('per_page', 30)));
    }

    public function observe(Request $request, CognitiveAgent $agent, CognitiveLearningService $learning)
    {
        $this->authorizeCognition($request);
        $data = $request->validate([
            'event_type' => ['required', 'string', 'max:100'],
            'source_channel' => ['nullable', 'string', 'max:64'],
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'entity_type' => ['nullable', 'string', 'max:120'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'external_key' => ['nullable', 'string', 'max:191'],
            'payload' => ['nullable', 'array'],
            'salience' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        return response()->json($learning->recordObservation($agent, $data, $request->user()), 201);
    }

    public function feedback(Request $request, CognitiveAgent $agent, CognitiveLearningService $learning)
    {
        $this->authorizeCognition($request);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:100'],
            'predicate' => ['required', 'string', 'max:100'],
            'object_key' => ['required', 'string', 'max:191'],
            'value' => ['nullable', 'array'],
            'signal' => ['required', 'integer', 'in:-1,1'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'summary' => ['nullable', 'string', 'max:4000'],
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'entity_type' => ['nullable', 'string', 'max:120'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($learning->recordFeedback($agent, $data, $request->user()), 201);
    }

    public function storeGoal(Request $request, CognitiveAgent $agent, CognitiveLearningService $learning)
    {
        $this->authorizeCognition($request);
        $data = $request->validate([
            'origin' => ['nullable', 'in:system,user,self'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'priority' => ['nullable', 'integer', 'between:0,100'],
            'success_criteria' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
        ]);

        return response()->json($learning->createGoal($agent, $data, $request->user()), 201);
    }

    public function retractBelief(Request $request, CognitiveAgent $agent, CognitiveBelief $belief, CognitiveLearningService $learning)
    {
        $this->authorizeCognition($request);
        abort_unless($belief->agent_id === $agent->id, 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:4000']]);

        return response()->json($learning->retractBelief($belief, $request->user(), $data['reason'] ?? null));
    }
}
