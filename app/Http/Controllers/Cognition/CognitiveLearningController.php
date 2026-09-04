<?php

namespace App\Http\Controllers\Cognition;

use App\Models\CognitiveAgent;
use App\Models\CognitiveBelief;
use App\Services\CognitiveLearningService;
use Illuminate\Http\Request;

class CognitiveLearningController extends CognitionController
{
    public function observations(Request $request, CognitiveAgent $agent) { $this->authorizeCognition($request); return response()->json($agent->observations()->latest()->paginate(min(100,max(1,(int)$request->integer('per_page',30))))); }
    public function memories(Request $request, CognitiveAgent $agent) { $this->authorizeCognition($request); $q=$agent->memories()->latest(); if($request->filled('type'))$q->where('memory_type',$request->string('type')); if(!$request->boolean('include_inactive'))$q->where('active',true); return response()->json($q->paginate(min(100,max(1,(int)$request->integer('per_page',30))))); }
    public function beliefs(Request $request, CognitiveAgent $agent) { $this->authorizeCognition($request); $q=$agent->beliefs()->orderByDesc('confidence')->latest('last_evidence_at'); if($request->filled('status'))$q->where('status',$request->string('status')); return response()->json($q->paginate(min(100,max(1,(int)$request->integer('per_page',30))))); }
    public function goals(Request $request, CognitiveAgent $agent) { $this->authorizeCognition($request); return response()->json($agent->goals()->orderByDesc('priority')->latest()->paginate(50)); }
    public function learningEvents(Request $request, CognitiveAgent $agent) { $this->authorizeCognition($request); return response()->json($agent->learningEvents()->latest()->paginate(min(100,max(1,(int)$request->integer('per_page',30))))); }

    public function observe(Request $request, CognitiveAgent $agent, CognitiveLearningService $learning)
    {
        $this->authorizeCognition($request);
        $data=$request->validate(['event_type'=>['required','string','max:100'],'source_channel'=>['nullable','string','max:64'],'application_id'=>['nullable','integer','exists:applications,id'],'entity_type'=>['nullable','string','max:120'],'entity_id'=>['nullable','integer','min:1'],'external_key'=>['nullable','string','max:191'],'payload'=>['nullable','array'],'salience'=>['nullable','numeric','between:0,1']]);
        return response()->json($learning->recordObservation($agent,$data,$request->user()),201);
    }

    public function feedback(Request $request, CognitiveAgent $agent, CognitiveLearningService $learning)
    {
        $this->authorizeCognition($request);
        $data=$request->validate(['subject'=>['required','string','max:100'],'predicate'=>['required','string','max:100'],'object_key'=>['required','string','max:191'],'value'=>['nullable','array'],'signal'=>['required','integer','in:-1,1'],'confidence'=>['nullable','numeric','between:0,1'],'summary'=>['nullable','string','max:4000'],'application_id'=>['nullable','integer','exists:applications,id'],'entity_type'=>['nullable','string','max:120'],'entity_id'=>['nullable','integer','min:1']]);
        return response()->json($learning->recordFeedback($agent,$data,$request->user()),201);
    }

    public function storeGoal(Request $request, CognitiveAgent $agent, CognitiveLearningService $learning)
    {
        $this->authorizeCognition($request);
        $data=$request->validate(['origin'=>['nullable','in:system,user,self'],'title'=>['required','string','max:255'],'description'=>['nullable','string','max:4000'],'priority'=>['nullable','integer','between:0,100'],'success_criteria'=>['nullable','array'],'context'=>['nullable','array']]);
        return response()->json($learning->createGoal($agent,$data,$request->user()),201);
    }

    public function retractBelief(Request $request, CognitiveAgent $agent, CognitiveBelief $belief, CognitiveLearningService $learning)
    {
        $this->authorizeCognition($request); abort_unless($belief->agent_id===$agent->id,404); $data=$request->validate(['reason'=>['nullable','string','max:4000']]);
        return response()->json($learning->retractBelief($belief,$request->user(),$data['reason'] ?? null));
    }
}
