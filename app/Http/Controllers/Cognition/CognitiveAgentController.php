<?php

namespace App\Http\Controllers\Cognition;

use App\Models\CognitiveAgent;
use App\Services\CognitiveLearningService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CognitiveAgentController extends CognitionController
{
    public function index(Request $request) { $this->authorizeCognition($request); return response()->json(CognitiveAgent::query()->latest()->paginate(50)); }
    public function default(Request $request, CognitiveLearningService $learning) { $this->authorizeCognition($request); return response()->json($learning->defaultAgent()); }

    public function store(Request $request)
    {
        $this->authorizeCognition($request);
        $data = $request->validate([
            'name'=>['required','string','max:160'],'slug'=>['nullable','string','max:160','unique:cognitive_agents,slug'],'description'=>['nullable','string','max:4000'],
            'purpose'=>['nullable','string','max:4000'],'identity'=>['nullable','array'],'capabilities'=>['nullable','array'],'constraints'=>['nullable','array'],
            'values'=>['nullable','array'],'self_model'=>['nullable','array'],'learning_enabled'=>['nullable','boolean'],
        ]);
        $agent = CognitiveAgent::create(array_merge($data, [
            'slug'=>$data['slug'] ?? Str::slug($data['name']).'-'.Str::lower(Str::random(6)),'status'=>'active','learning_enabled'=>$data['learning_enabled'] ?? true,
            'metadata'=>['created_by'=>$request->user()->id,'schema_version'=>'0.1.0'],
        ]));
        return response()->json($agent, 201);
    }

    public function show(Request $request, CognitiveAgent $agent) { $this->authorizeCognition($request); return response()->json($agent->loadCount(['observations','memories','beliefs','goals','experimentRuns'])); }

    public function update(Request $request, CognitiveAgent $agent)
    {
        $this->authorizeCognition($request);
        $data = $request->validate([
            'name'=>['sometimes','string','max:160'],'description'=>['nullable','string','max:4000'],'purpose'=>['nullable','string','max:4000'],'identity'=>['nullable','array'],
            'capabilities'=>['nullable','array'],'constraints'=>['nullable','array'],'values'=>['nullable','array'],'self_model'=>['nullable','array'],'status'=>['sometimes','in:active,paused,disabled'],'learning_enabled'=>['sometimes','boolean'],
        ]);
        $agent->update($data);
        return response()->json($agent->fresh());
    }

    public function state(Request $request, CognitiveAgent $agent, CognitiveLearningService $learning) { $this->authorizeCognition($request); return response()->json($learning->captureState($agent)); }
}
