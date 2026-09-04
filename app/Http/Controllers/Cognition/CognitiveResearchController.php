<?php

namespace App\Http\Controllers\Cognition;

use App\Models\CognitiveAgent;
use App\Models\CognitiveExperiment;
use App\Services\CognitiveLearningService;
use Illuminate\Http\Request;

class CognitiveResearchController extends CognitionController
{
    public function framework(Request $request, CognitiveLearningService $learning) { $this->authorizeCognition($request); return response()->json($learning->researchFramework()); }
    public function bootstrap(Request $request, CognitiveLearningService $learning) { $this->authorizeCognition($request); return response()->json(['experiments_upserted'=>$learning->bootstrapExperiments()]); }
    public function experiments(Request $request) { $this->authorizeCognition($request); return response()->json(CognitiveExperiment::query()->where('enabled',true)->orderBy('dimension')->get()); }
    public function runs(Request $request, CognitiveAgent $agent) { $this->authorizeCognition($request); return response()->json($agent->experimentRuns()->with('experiment')->latest()->paginate(50)); }
    public function recordRun(Request $request, CognitiveAgent $agent, CognitiveExperiment $experiment, CognitiveLearningService $learning)
    {
        $this->authorizeCognition($request);
        $data=$request->validate(['input'=>['nullable','array'],'evidence'=>['nullable','array'],'metrics'=>['nullable','array'],'score'=>['nullable','numeric','between:0,1'],'result'=>['nullable','in:pass,fail,inconclusive'],'notes'=>['nullable','string','max:10000'],'started_at'=>['nullable','date']]);
        return response()->json($learning->recordExperimentRun($experiment,$agent,$data,$request->user()),201);
    }
}
