<?php

namespace App\Http\Controllers\Cognition;

use App\Models\CognitiveAgent;
use App\Services\CognitiveLearningService;
use App\Services\CognitiveQueryService;
use Illuminate\Http\Request;

class CognitiveAgentController extends CognitionController
{
    public function index(Request $request, CognitiveQueryService $queries)
    {
        $this->authorizeCognition($request);
        return response()->json($queries->paginateAgents((int) $request->integer('per_page', 50)));
    }

    public function default(Request $request, CognitiveLearningService $learning)
    {
        $this->authorizeCognition($request);
        return response()->json($learning->defaultAgent());
    }

    public function store(Request $request, CognitiveQueryService $queries)
    {
        $this->authorizeCognition($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:160', 'unique:cognitive_agents,slug'],
            'description' => ['nullable', 'string', 'max:4000'],
            'purpose' => ['nullable', 'string', 'max:4000'],
            'identity' => ['nullable', 'array'],
            'capabilities' => ['nullable', 'array'],
            'constraints' => ['nullable', 'array'],
            'values' => ['nullable', 'array'],
            'self_model' => ['nullable', 'array'],
            'learning_enabled' => ['nullable', 'boolean'],
        ]);

        return response()->json($queries->createAgent($data, $request->user()), 201);
    }

    public function show(Request $request, CognitiveAgent $agent, CognitiveQueryService $queries)
    {
        $this->authorizeCognition($request);
        return response()->json($queries->agentSummary($agent));
    }

    public function dashboard(Request $request, CognitiveAgent $agent, CognitiveQueryService $queries)
    {
        $this->authorizeCognition($request);
        return response()->json($queries->dashboard($agent));
    }

    public function update(Request $request, CognitiveAgent $agent, CognitiveQueryService $queries)
    {
        $this->authorizeCognition($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'purpose' => ['nullable', 'string', 'max:4000'],
            'identity' => ['nullable', 'array'],
            'capabilities' => ['nullable', 'array'],
            'constraints' => ['nullable', 'array'],
            'values' => ['nullable', 'array'],
            'self_model' => ['nullable', 'array'],
            'status' => ['sometimes', 'in:active,paused,disabled'],
            'learning_enabled' => ['sometimes', 'boolean'],
        ]);

        return response()->json($queries->updateAgent($agent, $data));
    }

    public function state(Request $request, CognitiveAgent $agent, CognitiveLearningService $learning)
    {
        $this->authorizeCognition($request);
        return response()->json($learning->captureState($agent));
    }
}
