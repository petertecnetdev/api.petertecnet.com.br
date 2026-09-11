<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappCheckinTokenPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_responses_do_not_expose_qr_token_or_cross_event_pass_data(): void
    {
        $application = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = $this->user('Owner', 'privacy-owner@cutinapp.test');
        $participant = $this->user('Participant', 'privacy-participant@cutinapp.test');
        $operator = $this->user('Operator', 'privacy-operator@cutinapp.test');
        $operatorProfile = Profile::create(['name'=>'Cutinapp Privacy Checkin Operator','permissions'=>['event_checkin','ticket_checkin']]);
        $operator->update(['profile_id' => $operatorProfile->id]);

        $production = $this->withHeaders($this->headersFor($owner))->postJson('/api/cutinapp/productions', [
            'name'=>'Produção Privacidade','city'=>'São Paulo','uf'=>'SP',
        ])->assertCreated()->json('production');

        [$firstEvent] = $this->createPublishedEventWithCourtesy($owner, $production['id'], 'Evento Portaria A');
        [$secondEvent, $secondTicket] = $this->createPublishedEventWithCourtesy($owner, $production['id'], 'Evento Portaria B');

        $claimedPass = $this->withHeaders($this->headersFor($participant))->postJson('/api/cutinapp/passes/claim/' . $secondTicket['id'])->assertCreated()->json('pass');
        $token = $claimedPass['token'];

        $walletPass = $this->withHeaders($this->headersFor($participant))
            ->getJson('/api/cutinapp/passes/mine')
            ->assertOk()
            ->json('passes.0');
        $this->assertArrayNotHasKey('token', $walletPass);
        $this->assertTrue((bool) data_get($walletPass, 'secure_qr.available'));

        DB::table('application_user')->updateOrInsert(
            ['application_id'=>$application->id,'user_id'=>$operator->id],
            ['role'=>'staff','status'=>'active','metadata'=>json_encode(['event_ids'=>[$firstEvent['id']]], JSON_THROW_ON_ERROR),'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now()]
        );

        $this->withHeaders($this->headersFor($operator))->postJson('/api/cutinapp/checkin', ['event_id'=>$firstEvent['id'],'token'=>$token])->assertStatus(422)->assertJsonPath('pass', null);

        $participants = $this->withHeaders($this->headersFor($owner))->getJson('/api/cutinapp/events/' . $secondEvent['id'] . '/participants')->assertOk()->json('passes');
        $this->assertNotEmpty($participants);
        $this->assertArrayNotHasKey('token', $participants[0]);

        $managedPass = $this->withHeaders($this->headersFor($owner))->getJson('/api/cutinapp/passes/' . $claimedPass['id'])->assertOk()->json('pass');
        $this->assertArrayNotHasKey('token', $managedPass);

        $this->travelTo(Carbon::parse($secondEvent['start_date'])->addMinute());
        $checkinPass = $this->withHeaders($this->headersFor($owner))->postJson('/api/cutinapp/checkin', ['event_id'=>$secondEvent['id'],'token'=>$token])->assertOk()->json('pass');
        $this->assertArrayNotHasKey('token', $checkinPass);
        $this->travelBack();

        $ownPass = $this->withHeaders($this->headersFor($participant))->getJson('/api/cutinapp/passes/' . $claimedPass['id'])->assertOk()->json('pass');
        $this->assertSame($token, $ownPass['token'] ?? null);
    }

    private function createPublishedEventWithCourtesy(User $owner, int $productionId, string $title): array
    {
        $event = $this->withHeaders($this->headersFor($owner))->postJson('/api/cutinapp/events', [
            'production_id'=>$productionId,'title'=>$title,'description'=>'Evento usado para validar privacidade dos tokens de ingresso.','address'=>'Rua Privacidade, 10',
            'start_date'=>now()->addDay()->format('Y-m-d H:i:s'),'end_date'=>now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('event');
        $ticket = $this->withHeaders($this->headersFor($owner))->postJson('/api/cutinapp/courtesies', ['event_id'=>$event['id'],'name'=>'Cortesia ' . $title,'quantity'=>10])->assertCreated()->json('ticket');
        $this->withHeaders($this->headersFor($owner))->postJson('/api/cutinapp/events/' . $event['id'] . '/publish')->assertOk();
        return [$event, $ticket];
    }

    private function headersFor(User $user): array
    {
        return ['Authorization'=>'Bearer ' . JWTAuth::fromUser($user),'X-Peter-App'=>'cutinapp'];
    }

    private function user(string $name, string $email): User
    {
        return User::create(['first_name'=>$name,'email'=>$email,'user_name'=>strtolower($name) . '-' . substr(md5($email),0,8),'password'=>Hash::make('Test1234!'),'email_verified_at'=>now()]);
    }
}
