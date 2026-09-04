<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\CognitiveBelief;
use App\Models\Interaction;
use App\Models\Profile;
use App\Models\User;
use App\Services\CognitiveLearningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CognitiveLearningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_interaction_learning_is_idempotent_and_sanitized(): void
    {
        config(['cognition.enabled' => false]);
        $application = Application::create(['name'=>'Generic App','slug'=>'generic-app','is_active'=>true]);
        $interaction = Interaction::create([
            'app_id'=>$application->id,'interaction_type'=>'update','outcome'=>'success','severity'=>'info','environment'=>'testing',
            'route'=>'api/items/1','method'=>'PUT','entity_type'=>'Item','entity_id'=>1,'name'=>'Update item',
            'content'=>['status'=>'ok','duration_ms'=>42,'email'=>'private@example.test','token'=>'must-not-be-learned'],
        ]);

        config(['cognition.enabled'=>true,'cognition.auto_learn'=>true]);
        $learning = app(CognitiveLearningService::class);
        $first = $learning->observeInteraction($interaction);
        $second = $learning->observeInteraction($interaction);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('cognitive_observations', 1);
        $this->assertDatabaseCount('cognitive_beliefs', 1);
        $this->assertDatabaseCount('cognitive_memories', 1);
        $payload = $first->fresh()->payload;
        $this->assertSame('ok', $payload['content']['status']);
        $this->assertArrayNotHasKey('email', $payload['content']);
        $this->assertArrayNotHasKey('token', $payload['content']);
    }

    public function test_corrective_feedback_reduces_belief_confidence_and_is_audited(): void
    {
        config(['cognition.enabled'=>true]);
        $profile = Profile::create(['name'=>'Administrador','permissions'=>[]]);
        $user = User::create(['first_name'=>'Researcher','email'=>'researcher@example.test','user_name'=>'researcher','password'=>Hash::make('Test1234!'),'profile_id'=>$profile->id]);
        $learning = app(CognitiveLearningService::class);
        $agent = $learning->defaultAgent();

        $positive = $learning->recordFeedback($agent, [
            'subject'=>'catalog','predicate'=>'preference','object_key'=>'layout:compact','value'=>['preferred'=>true],'signal'=>1,'confidence'=>0.95,'summary'=>'O layout compacto funcionou melhor.',
        ], $user);
        $before = (float)$positive['belief']->confidence;

        $negative = $learning->recordFeedback($agent, [
            'subject'=>'catalog','predicate'=>'preference','object_key'=>'layout:compact','value'=>['preferred'=>false],'signal'=>-1,'confidence'=>1.0,'summary'=>'Correção: o layout compacto não é preferido neste contexto.',
        ], $user);
        $belief = CognitiveBelief::findOrFail($negative['belief']->id);
        $this->assertLessThan($before, (float)$belief->confidence);
        $this->assertSame(1, $belief->contradiction_count);
        $this->assertDatabaseHas('cognitive_learning_events', ['belief_id'=>$belief->id,'event_type'=>'belief_corrected']);
    }

    public function test_state_snapshot_contains_identity_and_goals(): void
    {
        config(['cognition.enabled'=>true]);
        $learning = app(CognitiveLearningService::class);
        $agent = $learning->defaultAgent();
        $learning->createGoal($agent, ['origin'=>'system','title'=>'Improve calibration','priority'=>90,'success_criteria'=>['calibration_error'=>'<=0.10']]);
        $snapshot = $learning->captureState($agent);
        $this->assertSame($agent->id, $snapshot->agent_id);
        $this->assertSame($agent->identity['continuity_key'], $snapshot->self_model['identity']['continuity_key']);
        $this->assertNotEmpty($snapshot->active_goals);
        $this->assertArrayHasKey('belief_count', $snapshot->metrics);
    }
}
