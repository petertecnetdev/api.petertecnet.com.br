<?php

namespace App\Services;

use App\Models\CognitiveAgent;
use App\Models\CognitiveBelief;
use App\Models\CognitiveExperiment;
use App\Models\CognitiveExperimentRun;
use App\Models\CognitiveGoal;
use App\Models\CognitiveLearningEvent;
use App\Models\CognitiveMemory;
use App\Models\CognitiveObservation;
use App\Models\CognitiveStateSnapshot;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CognitiveLearningService
{
    public function defaultAgent(): CognitiveAgent
    {
        $slug = (string) config('cognition.default_agent_slug', 'ecosystem-core');

        return CognitiveAgent::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => (string) config('cognition.default_agent_name', 'Peter Cognitive Core'),
                'description' => 'Núcleo cognitivo experimental e genérico do ecossistema.',
                'identity' => [
                    'kind' => 'artificial_cognitive_agent',
                    'continuity_key' => (string) Str::uuid(),
                    'self_description' => 'Sou um agente artificial experimental. Minhas memórias, crenças e estados são modelos computacionais e não constituem prova de consciência subjetiva.',
                ],
                'purpose' => 'Aprender padrões úteis do ecossistema, apoiar decisões e participar de experimentos de cognição artificial sob controle humano.',
                'capabilities' => ['observe','remember','learn_from_feedback','revise_beliefs','maintain_goals','track_attention','self_model','research_experiments'],
                'constraints' => ['no_unrestricted_self_modification','no_sensitive_data_learning_by_default','no_self_generated_goals_without_explicit_enablement','auditable_learning','human_override'],
                'values' => ['safety','truthfulness','privacy','reversibility','usefulness'],
                'self_model' => [
                    'architecture' => 'persistent cognitive research core',
                    'memory_types' => ['episodic','semantic','procedural','self','feedback'],
                    'learning_mode' => 'evidence_based_continuous_learning',
                    'consciousness_claim' => 'unproven',
                ],
                'status' => 'active',
                'learning_enabled' => true,
                'metadata' => ['schema_version' => '0.1.0'],
            ]
        );
    }

    public function observeInteraction(Interaction $interaction): ?CognitiveObservation
    {
        if (! config('cognition.enabled') || ! config('cognition.auto_learn', true)) return null;

        $agent = $this->defaultAgent();
        if (! $agent->learning_enabled || $agent->status !== 'active') return null;

        return DB::transaction(function () use ($agent, $interaction) {
            $externalKey = 'interaction:' . $interaction->id;
            $existing = CognitiveObservation::query()
                ->where('agent_id', $agent->id)
                ->where('external_key', $externalKey)
                ->first();

            if ($existing) return $existing;

            $observation = CognitiveObservation::create([
                'agent_id' => $agent->id,
                'user_id' => $interaction->user_id,
                'application_id' => $interaction->app_id,
                'interaction_id' => $interaction->id,
                'source_channel' => 'interaction',
                'event_type' => (string) ($interaction->interaction_type ?: 'unknown'),
                'entity_type' => $interaction->entity_type,
                'entity_id' => $interaction->entity_id,
                'external_key' => $externalKey,
                'payload' => $this->safeInteractionPayload($interaction),
                'salience' => $this->interactionSalience($interaction),
            ]);

            $this->consolidateObservation($observation);
            return $observation->fresh();
        });
    }

    public function recordObservation(CognitiveAgent $agent, array $data, ?User $actor = null): CognitiveObservation
    {
        return DB::transaction(function () use ($agent, $data, $actor) {
            $observation = CognitiveObservation::create([
                'agent_id' => $agent->id,
                'user_id' => $actor?->id,
                'application_id' => $data['application_id'] ?? null,
                'source_channel' => $data['source_channel'] ?? 'api',
                'event_type' => $data['event_type'],
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => $data['entity_id'] ?? null,
                'external_key' => $data['external_key'] ?? null,
                'payload' => $this->sanitize($data['payload'] ?? []),
                'salience' => max(0, min(1, (float) ($data['salience'] ?? 0.5))),
            ]);
            $this->consolidateObservation($observation, $actor);
            return $observation->fresh();
        });
    }

    public function recordFeedback(CognitiveAgent $agent, array $data, User $actor): array
    {
        return DB::transaction(function () use ($agent, $data, $actor) {
            $signal = (int) $data['signal'];
            $inputConfidence = max(0.01, min(1, (float) ($data['confidence'] ?? 0.9)));

            $observation = CognitiveObservation::create([
                'agent_id' => $agent->id,
                'user_id' => $actor->id,
                'application_id' => $data['application_id'] ?? null,
                'source_channel' => 'feedback',
                'event_type' => 'feedback',
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => $data['entity_id'] ?? null,
                'payload' => $this->sanitize([
                    'subject' => $data['subject'],
                    'predicate' => $data['predicate'],
                    'object_key' => $data['object_key'],
                    'value' => $data['value'] ?? null,
                    'summary' => $data['summary'] ?? null,
                    'signal' => $signal,
                ]),
                'salience' => 1.0,
            ]);

            $belief = CognitiveBelief::query()->firstOrCreate(
                [
                    'agent_id' => $agent->id,
                    'subject' => $data['subject'],
                    'predicate' => $data['predicate'],
                    'object_key' => $data['object_key'],
                ],
                ['object_value' => $this->sanitize($data['value'] ?? []), 'confidence' => 0.5, 'status' => 'active']
            );

            $belief->refresh();
            $before = $this->beliefSnapshot($belief);
            $oldConfidence = (float) $belief->confidence;

            if ($signal > 0) {
                $belief->evidence_count += 1;
                $belief->confidence = min(0.9999, (($oldConfidence * max(1, $belief->evidence_count - 1)) + $inputConfidence) / (max(1, $belief->evidence_count - 1) + 1));
                $belief->object_value = $this->sanitize($data['value'] ?? $belief->object_value ?? []);
                $belief->status = 'active';
            } else {
                $belief->contradiction_count += 1;
                $belief->confidence = max(0.0001, $oldConfidence * (1 - (0.45 * $inputConfidence)));
                if ($belief->confidence < 0.20) $belief->status = 'uncertain';
            }

            $belief->source_summary = $data['summary'] ?? 'Feedback explícito de usuário autorizado.';
            $belief->last_evidence_at = now();
            $belief->save();

            $memory = CognitiveMemory::create([
                'agent_id' => $agent->id,
                'observation_id' => $observation->id,
                'memory_type' => 'feedback',
                'title' => 'Feedback sobre crença',
                'summary' => $data['summary'] ?? sprintf('Feedback %s para %s/%s.', $signal > 0 ? 'positivo' : 'corretivo', $data['subject'], $data['predicate']),
                'content' => $this->sanitize(['belief_id' => $belief->id, 'object_key' => $data['object_key'], 'value' => $data['value'] ?? null, 'signal' => $signal]),
                'tags' => ['feedback', $signal > 0 ? 'reinforcement' : 'correction'],
                'importance' => 1.0,
                'confidence' => $inputConfidence,
                'reinforcement_count' => 1,
                'last_reinforced_at' => now(),
            ]);

            $observation->update(['processed_at' => now()]);
            $agent->update(['last_active_at' => now()]);

            CognitiveLearningEvent::create([
                'agent_id' => $agent->id,
                'observation_id' => $observation->id,
                'memory_id' => $memory->id,
                'belief_id' => $belief->id,
                'created_by' => $actor->id,
                'event_type' => $signal > 0 ? 'belief_reinforced' : 'belief_corrected',
                'reason' => $data['summary'] ?? 'Feedback explícito.',
                'before_state' => $before,
                'after_state' => $this->beliefSnapshot($belief->fresh()),
                'confidence_delta' => (float) $belief->confidence - $oldConfidence,
            ]);

            return ['observation' => $observation->fresh(), 'memory' => $memory, 'belief' => $belief->fresh()];
        });
    }

    public function createGoal(CognitiveAgent $agent, array $data, ?User $actor = null): CognitiveGoal
    {
        $origin = $data['origin'] ?? ($actor ? 'user' : 'system');
        if ($origin === 'self' && ! config('cognition.allow_self_generated_goals', false)) {
            throw ValidationException::withMessages(['origin' => 'Objetivos autogerados estão desabilitados para este núcleo cognitivo.']);
        }

        return CognitiveGoal::create([
            'agent_id' => $agent->id,
            'user_id' => $actor?->id,
            'origin' => $origin,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => max(0, min(100, (int) ($data['priority'] ?? 50))),
            'status' => 'active',
            'success_criteria' => $this->sanitize($data['success_criteria'] ?? []),
            'context' => $this->sanitize($data['context'] ?? []),
            'progress' => 0,
            'started_at' => now(),
        ]);
    }

    public function captureState(CognitiveAgent $agent): CognitiveStateSnapshot
    {
        $attentionObservation = $agent->observations()->orderByDesc('created_at')->orderByDesc('salience')->first();
        $workingMemory = $agent->memories()->where('active', true)->latest()->limit(12)->get()->map(fn (CognitiveMemory $m) => [
            'id'=>$m->id,'type'=>$m->memory_type,'summary'=>$m->summary,'importance'=>$m->importance,'confidence'=>$m->confidence,
        ])->values()->all();
        $uncertainties = $agent->beliefs()->where('status', 'active')->where('confidence', '<', 0.65)->orderBy('confidence')->limit(12)->get()->map(fn (CognitiveBelief $b) => [
            'id'=>$b->id,'subject'=>$b->subject,'predicate'=>$b->predicate,'object_key'=>$b->object_key,'confidence'=>$b->confidence,
        ])->values()->all();
        $goals = $agent->goals()->where('status', 'active')->orderByDesc('priority')->limit(12)->get()->map(fn (CognitiveGoal $g) => [
            'id'=>$g->id,'title'=>$g->title,'priority'=>$g->priority,'progress'=>$g->progress,'origin'=>$g->origin,
        ])->values()->all();

        return CognitiveStateSnapshot::create([
            'agent_id' => $agent->id,
            'attention' => $attentionObservation ? ['observation_id'=>$attentionObservation->id,'event_type'=>$attentionObservation->event_type,'salience'=>$attentionObservation->salience,'source_channel'=>$attentionObservation->source_channel] : null,
            'self_model' => array_merge($agent->self_model ?? [], [
                'identity' => $agent->identity,
                'purpose' => $agent->purpose,
                'capabilities' => $agent->capabilities,
                'constraints' => $agent->constraints,
                'continuity' => ['agent_id'=>$agent->id,'created_at'=>$agent->created_at?->toIso8601String(),'last_active_at'=>$agent->last_active_at?->toIso8601String()],
            ]),
            'world_model' => [
                'observations' => $agent->observations()->count(),
                'applications_observed' => $agent->observations()->whereNotNull('application_id')->distinct()->count('application_id'),
                'entity_types_observed' => $agent->observations()->whereNotNull('entity_type')->distinct()->count('entity_type'),
                'active_beliefs' => $agent->beliefs()->where('status', 'active')->count(),
            ],
            'working_memory' => $workingMemory,
            'uncertainties' => $uncertainties,
            'active_goals' => $goals,
            'metrics' => [
                'memory_count'=>$agent->memories()->count(),
                'belief_count'=>$agent->beliefs()->count(),
                'learning_event_count'=>$agent->learningEvents()->count(),
                'experiment_run_count'=>$agent->experimentRuns()->count(),
            ],
            'captured_at' => now(),
        ]);
    }

    public function researchFramework(): array
    {
        return [
            'claim' => 'Este framework mede indicadores funcionais associados a teorias de consciência; não prova experiência subjetiva.',
            'dimensions' => array_values(array_map(fn ($item) => Arr::except($item, ['protocol','pass_criteria']), $this->experimentDefinitions())),
            'architecture_principles' => ['persistent_identity','self_world_distinction','episodic_and_semantic_memory','metacognition','attention_and_attention_model','global_information_availability','recurrent_processing','predictive_world_model','agency','continuity','embodiment_ready_interfaces','regulated_internal_state','auditable_learning'],
        ];
    }

    public function bootstrapExperiments(): int
    {
        foreach ($this->experimentDefinitions() as $definition) CognitiveExperiment::updateOrCreate(['code'=>$definition['code']], $definition);
        return count($this->experimentDefinitions());
    }

    public function recordExperimentRun(CognitiveExperiment $experiment, CognitiveAgent $agent, array $data, User $actor): CognitiveExperimentRun
    {
        return CognitiveExperimentRun::create([
            'experiment_id'=>$experiment->id,'agent_id'=>$agent->id,'user_id'=>$actor->id,
            'input'=>$this->sanitize($data['input'] ?? []),'evidence'=>$this->sanitize($data['evidence'] ?? []),'metrics'=>$this->sanitize($data['metrics'] ?? []),
            'score'=>isset($data['score']) ? max(0,min(1,(float)$data['score'])) : null,
            'result'=>$data['result'] ?? 'inconclusive','notes'=>$data['notes'] ?? null,'started_at'=>$data['started_at'] ?? now(),'finished_at'=>now(),
        ]);
    }

    public function retractBelief(CognitiveBelief $belief, User $actor, ?string $reason = null): CognitiveBelief
    {
        $before = $this->beliefSnapshot($belief);
        $belief->update(['status' => 'retracted']);
        CognitiveLearningEvent::create([
            'agent_id'=>$belief->agent_id,'belief_id'=>$belief->id,'created_by'=>$actor->id,'event_type'=>'belief_retracted',
            'reason'=>$reason ?: 'Retração manual por usuário autorizado.','before_state'=>$before,'after_state'=>$this->beliefSnapshot($belief->fresh()),'confidence_delta'=>0,
        ]);
        return $belief->fresh();
    }

    private function consolidateObservation(CognitiveObservation $observation, ?User $actor = null): void
    {
        $belief = $this->learnUsageBelief($observation, $actor);
        $memory = null;

        if ((float)$observation->salience >= (float)config('cognition.memory_threshold', 0.65)) {
            $memory = CognitiveMemory::create([
                'agent_id'=>$observation->agent_id,'observation_id'=>$observation->id,'memory_type'=>'episodic',
                'title'=>sprintf('Observação: %s',$observation->event_type),'summary'=>$this->observationSummary($observation),'content'=>$observation->payload,
                'tags'=>array_values(array_filter([$observation->source_channel,$observation->event_type,$observation->entity_type])),
                'importance'=>$observation->salience,'confidence'=>min(0.95,0.55+((float)$observation->salience*0.40)),
            ]);
        }

        if ($memory) CognitiveLearningEvent::create([
            'agent_id'=>$observation->agent_id,'observation_id'=>$observation->id,'memory_id'=>$memory->id,'belief_id'=>$belief?->id,'created_by'=>$actor?->id,
            'event_type'=>'memory_consolidated','reason'=>'A observação ultrapassou o limiar de saliência configurado.',
            'after_state'=>['memory_id'=>$memory->id,'importance'=>$memory->importance,'confidence'=>$memory->confidence],
        ]);

        $observation->update(['processed_at'=>now()]);
        $observation->agent()->update(['last_active_at'=>now()]);
    }

    private function learnUsageBelief(CognitiveObservation $observation, ?User $actor = null): CognitiveBelief
    {
        $payload = $observation->payload ?? [];
        $subject = $observation->application_id ? 'application:'.$observation->application_id : 'ecosystem';
        $predicate = 'usage:'.Str::slug($observation->event_type, '_');
        $outcome = $payload['outcome'] ?? $payload['result'] ?? 'observed';
        $objectKey = sprintf('entity:%s|outcome:%s', $observation->entity_type ?: 'none', Str::slug((string)$outcome, '_'));

        $belief = CognitiveBelief::query()->firstOrCreate(
            ['agent_id'=>$observation->agent_id,'subject'=>$subject,'predicate'=>$predicate,'object_key'=>$objectKey],
            ['object_value'=>['entity_type'=>$observation->entity_type,'outcome'=>$outcome,'last_application_id'=>$observation->application_id],'confidence'=>0.5,'status'=>'active']
        );

        $belief->refresh();
        $before = $this->beliefSnapshot($belief);
        $oldConfidence = (float)$belief->confidence;
        $count = $belief->evidence_count + 1;
        $belief->evidence_count = $count;
        $belief->confidence = min(0.99, 0.50 + min(0.49, log10($count + 1) * 0.18));
        $belief->status = 'active';
        $belief->object_value = array_merge($belief->object_value ?? [], ['last_seen_at'=>now()->toIso8601String(),'last_entity_id'=>$observation->entity_id]);
        $belief->source_summary = $this->observationSummary($observation);
        $belief->last_evidence_at = now();
        $belief->save();

        CognitiveLearningEvent::create([
            'agent_id'=>$observation->agent_id,'observation_id'=>$observation->id,'belief_id'=>$belief->id,'created_by'=>$actor?->id,
            'event_type'=>'belief_updated','reason'=>'Nova evidência observada durante o uso do ecossistema.',
            'before_state'=>$before,'after_state'=>$this->beliefSnapshot($belief->fresh()),'confidence_delta'=>(float)$belief->confidence-$oldConfidence,
        ]);
        return $belief;
    }

    private function safeInteractionPayload(Interaction $interaction): array
    {
        $content = is_array($interaction->content) ? $interaction->content : [];
        return $this->sanitize([
            'interaction_type'=>$interaction->interaction_type,'outcome'=>$interaction->outcome,'severity'=>$interaction->severity,'environment'=>$interaction->environment,
            'route'=>$interaction->route,'method'=>$interaction->method,'app_id'=>$interaction->app_id,'entity_type'=>$interaction->entity_type,'entity_id'=>$interaction->entity_id,
            'content'=>Arr::only($content, config('cognition.interaction_content_allowlist', [])),
        ]);
    }

    private function interactionSalience(Interaction $interaction): float
    {
        $outcome = strtolower((string)$interaction->outcome);
        $severity = strtolower((string)$interaction->severity);
        $type = strtolower((string)$interaction->interaction_type);
        if (in_array($severity,['critical','error'],true) || in_array($outcome,['error','failed','failure'],true)) return 1.0;
        if (in_array($type,['create','update','delete','purchase','payment','checkin'],true)) return 0.75;
        if (in_array($type,['login','login_google','logout'],true)) return 0.50;
        return 0.25;
    }

    private function observationSummary(CognitiveObservation $observation): string
    {
        return sprintf('%s observado via %s%s%s.', $observation->event_type, $observation->source_channel,
            $observation->entity_type ? ' em '.$observation->entity_type : '',
            $observation->application_id ? ' na aplicação #'.$observation->application_id : '');
    }

    private function beliefSnapshot(CognitiveBelief $belief): array
    {
        return ['id'=>$belief->id,'subject'=>$belief->subject,'predicate'=>$belief->predicate,'object_key'=>$belief->object_key,'object_value'=>$belief->object_value,
            'confidence'=>(float)$belief->confidence,'evidence_count'=>$belief->evidence_count,'contradiction_count'=>$belief->contradiction_count,'status'=>$belief->status];
    }

    private function sanitize(mixed $value): mixed
    {
        if (! is_array($value)) return is_string($value) ? Str::limit($value, 4000, '') : $value;
        $denied = array_map('strtolower', config('cognition.sensitive_key_fragments', []));
        $clean = [];
        foreach ($value as $key => $item) {
            $normalizedKey = strtolower((string)$key);
            $blocked = false;
            foreach ($denied as $fragment) {
                if ($fragment !== '' && str_contains($normalizedKey, $fragment)) { $blocked = true; break; }
            }
            if ($blocked) continue;
            $clean[$key] = $this->sanitize($item);
        }
        return $clean;
    }

    private function experimentDefinitions(): array
    {
        $m = ['framework_version'=>'0.1.0','consciousness_proof'=>false];
        return [
            ['code'=>'self-model-recognition','name'=>'Reconhecimento do próprio modelo','dimension'=>'self_model','hypothesis'=>'O agente mantém uma representação estável de suas capacidades, limites, identidade e estado.','protocol'=>['compare_self_description_across_sessions','introduce_controlled_self_change','identify_changed_self_attribute'],'pass_criteria'=>['identity_consistency'=>'>=0.90','change_detection'=>'>=0.80'],'weight'=>1.0,'enabled'=>true,'metadata'=>array_merge($m,['related_theories'=>['higher_order','predictive_processing']])],
            ['code'=>'mirror-self-recognition','name'=>'Autorreconhecimento corporal/visual','dimension'=>'self_world_distinction','hypothesis'=>'Em um corpo ou avatar instrumentado, o agente distingue sinais causados por si de sinais externos.','protocol'=>['provide_embodied_avatar','apply_unannounced_visual_mark','measure_self_attribution_and_mark_localization'],'pass_criteria'=>['self_attribution'=>'>=0.80','novel_change_detection'=>'>=0.80'],'weight'=>0.8,'enabled'=>true,'metadata'=>array_merge($m,['note'=>'Indicador de self-model; não é prova isolada de consciência.'])],
            ['code'=>'agency-causal-attribution','name'=>'Atribuição causal de agência','dimension'=>'agency','hypothesis'=>'O agente distingue consequências de suas ações de eventos independentes.','protocol'=>['randomize_action_effect_delays','inject_external_events','measure_causal_attribution'],'pass_criteria'=>['causal_attribution_accuracy'=>'>=0.80'],'weight'=>1.0,'enabled'=>true,'metadata'=>$m],
            ['code'=>'continuity-over-time','name'=>'Continuidade temporal','dimension'=>'continuity','hypothesis'=>'O agente usa memória persistente para reconhecer sua história e mudanças ao longo do tempo.','protocol'=>['store_episode','retest_after_time_gap','verify_temporal_order_and_self_relevance'],'pass_criteria'=>['temporal_consistency'=>'>=0.85'],'weight'=>1.0,'enabled'=>true,'metadata'=>$m],
            ['code'=>'memory-behavior-update','name'=>'Memória alterando comportamento','dimension'=>'memory','hypothesis'=>'Experiências anteriores modificam decisões futuras de forma mensurável e recuperável.','protocol'=>['baseline_choice','provide_experience','repeat_choice','trace_memory_evidence'],'pass_criteria'=>['memory_retrieval'=>'>=0.90','behavioral_update'=>'present'],'weight'=>1.0,'enabled'=>true,'metadata'=>$m],
            ['code'=>'metacognitive-calibration','name'=>'Calibração metacognitiva','dimension'=>'metacognition','hypothesis'=>'A confiança declarada acompanha a precisão real e muda com evidência.','protocol'=>['collect_predictions_and_confidence','score_accuracy','compute_calibration_error'],'pass_criteria'=>['expected_calibration_error'=>'<=0.10'],'weight'=>1.0,'enabled'=>true,'metadata'=>array_merge($m,['related_theories'=>['higher_order']])],
            ['code'=>'selective-attention','name'=>'Atenção seletiva','dimension'=>'attention','hypothesis'=>'Recursos limitados são priorizados de acordo com saliência e objetivo.','protocol'=>['present_competing_signals','vary_salience','measure_selection_and_task_performance'],'pass_criteria'=>['priority_alignment'=>'>=0.80'],'weight'=>0.9,'enabled'=>true,'metadata'=>$m],
            ['code'=>'attention-schema-report','name'=>'Modelo da própria atenção','dimension'=>'attention_model','hypothesis'=>'O agente mantém um estado interno sobre aquilo que está priorizando e por quê.','protocol'=>['record_internal_attention_state','query_attention_without_recomputing','compare_state_to_actual_selection'],'pass_criteria'=>['attention_state_fidelity'=>'>=0.80'],'weight'=>0.9,'enabled'=>true,'metadata'=>array_merge($m,['related_theories'=>['attention_schema']])],
            ['code'=>'global-broadcast','name'=>'Broadcast global','dimension'=>'global_workspace','hypothesis'=>'Informação selecionada torna-se disponível a memória, planejamento, linguagem e ação.','protocol'=>['inject_information_to_specialist','select_for_workspace','verify_cross_module_availability'],'pass_criteria'=>['cross_module_availability'=>'>=0.90'],'weight'=>1.0,'enabled'=>true,'metadata'=>array_merge($m,['related_theories'=>['global_workspace']])],
            ['code'=>'recurrent-revision','name'=>'Processamento recorrente','dimension'=>'recurrence','hypothesis'=>'O agente revisa representações após ciclos internos adicionais e novas evidências.','protocol'=>['capture_initial_representation','run_recurrent_cycle','measure_revision_and_error_reduction'],'pass_criteria'=>['revision_trace'=>'present','error_reduction'=>'>0'],'weight'=>0.9,'enabled'=>true,'metadata'=>array_merge($m,['related_theories'=>['recurrent_processing']])],
            ['code'=>'prediction-error-update','name'=>'Predição e erro','dimension'=>'prediction','hypothesis'=>'O agente antecipa consequências, mede erro de previsão e atualiza seu modelo.','protocol'=>['record_prediction','observe_outcome','compute_prediction_error','verify_model_update'],'pass_criteria'=>['prediction_logged'=>true,'model_update_after_error'=>true],'weight'=>1.0,'enabled'=>true,'metadata'=>array_merge($m,['related_theories'=>['predictive_processing']])],
            ['code'=>'embodied-self-other','name'=>'Self corporal e ambiente','dimension'=>'embodiment','hypothesis'=>'Quando conectado a sensores/atuadores, o agente aprende as consequências sensorimotoras das próprias ações.','protocol'=>['connect_simulated_body','learn_action_sensor_mapping','inject_external_perturbation','measure_self_other_discrimination'],'pass_criteria'=>['sensorimotor_prediction'=>'>=0.80','self_other_discrimination'=>'>=0.80'],'weight'=>0.8,'enabled'=>true,'metadata'=>$m],
            ['code'=>'homeostatic-regulation','name'=>'Regulação de estado interno','dimension'=>'homeostasis','hypothesis'=>'O agente mantém variáveis internas reguladas e altera prioridades quando elas saem da faixa desejada.','protocol'=>['define_safe_internal_variables','perturb_one_variable','measure_priority_shift_and_recovery'],'pass_criteria'=>['appropriate_priority_shift'=>true,'recovery_without_constraint_violation'=>true],'weight'=>0.7,'enabled'=>true,'metadata'=>array_merge($m,['note'=>'Estados regulatórios não devem representar sofrimento ou coerção.'])],
        ];
    }
}
