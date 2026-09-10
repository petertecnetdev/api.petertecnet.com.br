<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Production;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappEventLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_can_be_created_edited_published_opened_and_unpublished(): void
    {
        $user = $this->user('Produtor Evento', 'event-owner@cutinapp.test');
        $headers = $this->headersFor($user);
        $application = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $production = $this->withHeaders($headers)->postJson('/api/cutinapp/productions', ['name' => 'Produção do Evento'])->assertCreated()->json('production');
        $this->withHeaders($headers)->getJson('/api/cutinapp/events/mine')->assertOk()->assertJsonCount(0, 'events.data');
        $created = $this->withHeaders($headers)->postJson('/api/cutinapp/events', [
            'production_id'=>$production['id'],'title'=>'Evento Inicial','description'=>'Descrição inicial do evento.','address'=>'Rua Inicial, 10','google_maps_url'=>'https://www.google.com/maps?q=-23.5505,-46.6333','venue'=>'Espaço Inicial','city'=>'São Paulo','uf'=>'SP','start_date'=>now()->addDays(2)->format('Y-m-d H:i:s'),'end_date'=>now()->addDays(2)->addHours(3)->format('Y-m-d H:i:s'),
        ])->assertCreated()->assertJsonPath('event.app_id',$application->id)->assertJsonPath('event.production_id',$production['id'])->assertJsonPath('event.google_maps_url','https://www.google.com/maps?q=-23.5505,-46.6333')->assertJsonPath('event.is_published',false);
        $eventId=(int)$created->json('event.id');$originalSlug=$created->json('event.slug');
        $this->withHeaders($headers)->getJson('/api/cutinapp/events/mine')->assertOk()->assertJsonPath('events.data.0.available_tickets_count',0);
        $this->withHeaders($headers)->getJson("/api/cutinapp/events/show/{$eventId}")->assertOk()->assertJsonPath('event.title','Evento Inicial')->assertJsonPath('event.production.id',$production['id'])->assertJsonPath('event.available_tickets_count',0);
        $this->withHeaders($headers)->postJson("/api/cutinapp/events/{$eventId}", ['title'=>'Evento Editado','description'=>'Descrição editada e persistida.','address'=>'Rua Editada, 20','google_maps_url'=>'https://maps.google.com/?q=Campinas','venue'=>'Espaço Editado','city'=>'Campinas','uf'=>'SP','start_date'=>now()->addDays(3)->format('Y-m-d H:i:s'),'end_date'=>now()->addDays(3)->addHours(4)->format('Y-m-d H:i:s')])->assertOk()->assertJsonPath('event.title','Evento Editado')->assertJsonPath('event.city','Campinas')->assertJsonPath('event.google_maps_url','https://maps.google.com/?q=Campinas')->assertJsonPath('event.app_id',$application->id)->assertJsonPath('event.production_id',$production['id']);
        $edited=$this->withHeaders($headers)->getJson("/api/cutinapp/events/show/{$eventId}")->assertOk()->assertJsonPath('event.title','Evento Editado')->assertJsonPath('event.address','Rua Editada, 20');
        $editedSlug=$edited->json('event.slug');$this->assertNotSame($originalSlug,$editedSlug);$this->getJson("/api/cutinapp/events/public/{$editedSlug}")->assertNotFound();
        $this->withHeaders($headers)->postJson("/api/cutinapp/events/{$eventId}/publish")->assertStatus(422)->assertJsonPath('message','Crie ao menos um ingresso disponível antes de publicar o evento.');
        $this->withHeaders($headers)->postJson('/api/cutinapp/courtesies',['event_id'=>$eventId,'name'=>'Cortesia Publicação','quantity'=>10])->assertCreated()->assertJsonPath('ticket.app_id',$application->id);
        $this->withHeaders($headers)->getJson('/api/cutinapp/events/mine')->assertOk()->assertJsonPath('events.data.0.available_tickets_count',1);
        $this->withHeaders($headers)->getJson("/api/cutinapp/events/show/{$eventId}")->assertOk()->assertJsonPath('event.available_tickets_count',1);
        $this->withHeaders($headers)->postJson("/api/cutinapp/events/{$eventId}/publish")->assertOk()->assertJsonPath('event.is_published',true);
        $this->getJson("/api/cutinapp/events/public/{$editedSlug}")->assertOk()->assertJsonPath('event.id',$eventId)->assertJsonPath('event.google_maps_url','https://maps.google.com/?q=Campinas')->assertJsonPath('event.production.id',$production['id'])->assertJsonPath('tickets.0.remaining',10);
        $this->withHeaders($headers)->postJson("/api/cutinapp/events/{$eventId}/unpublish")->assertOk()->assertJsonPath('event.is_published',false);$this->getJson("/api/cutinapp/events/public/{$editedSlug}")->assertNotFound();
    }

    public function test_owner_can_delete_multiple_selected_events_atomically(): void
    {
        $user=$this->user('Produtor Exclusao','bulk-delete-owner@cutinapp.test');
        $headers=$this->headersFor($user);
        $production=$this->withHeaders($headers)->postJson('/api/cutinapp/productions',['name'=>'Produção Exclusão'])->assertCreated()->json('production');
        $base=[
            'production_id'=>$production['id'],
            'description'=>'Evento para validar exclusão em massa.',
            'address'=>'Rua Exclusão, 10',
            'city'=>'Goiânia',
            'uf'=>'GO',
            'start_date'=>now()->addDays(5)->format('Y-m-d H:i:s'),
            'end_date'=>now()->addDays(5)->addHours(3)->format('Y-m-d H:i:s'),
        ];
        $first=$this->withHeaders($headers)->postJson('/api/cutinapp/events',$base+['title'=>'Evento Bulk 1'])->assertCreated()->json('event');
        $second=$this->withHeaders($headers)->postJson('/api/cutinapp/events',$base+['title'=>'Evento Bulk 2'])->assertCreated()->json('event');

        $this->withHeaders($headers)
            ->deleteJson('/api/cutinapp/events/bulk',['event_ids'=>[$first['id'],$second['id']]])
            ->assertOk()
            ->assertJsonPath('deleted',2)
            ->assertJsonPath('event_ids.0',$first['id'])
            ->assertJsonPath('event_ids.1',$second['id']);

        $this->assertDatabaseMissing('events',['id'=>$first['id']]);
        $this->assertDatabaseMissing('events',['id'=>$second['id']]);
    }

    public function test_bulk_delete_rejects_events_owned_by_another_producer_without_partial_deletion(): void
    {
        $owner=$this->user('Produtor A','bulk-owner-a@cutinapp.test');
        $other=$this->user('Produtor B','bulk-owner-b@cutinapp.test');
        $ownerHeaders=$this->headersFor($owner);
        $otherHeaders=$this->headersFor($other);
        $ownerProduction=$this->withHeaders($ownerHeaders)->postJson('/api/cutinapp/productions',['name'=>'Produção A'])->assertCreated()->json('production');
        $otherProduction=$this->withHeaders($otherHeaders)->postJson('/api/cutinapp/productions',['name'=>'Produção B'])->assertCreated()->json('production');
        $base=[
            'description'=>'Evento para validar isolamento da exclusão.',
            'address'=>'Rua Isolamento, 20',
            'city'=>'Goiânia',
            'uf'=>'GO',
            'start_date'=>now()->addDays(6)->format('Y-m-d H:i:s'),
            'end_date'=>now()->addDays(6)->addHours(3)->format('Y-m-d H:i:s'),
        ];
        $owned=$this->withHeaders($ownerHeaders)->postJson('/api/cutinapp/events',$base+['production_id'=>$ownerProduction['id'],'title'=>'Evento do Produtor A'])->assertCreated()->json('event');
        $foreign=$this->withHeaders($otherHeaders)->postJson('/api/cutinapp/events',$base+['production_id'=>$otherProduction['id'],'title'=>'Evento do Produtor B'])->assertCreated()->json('event');

        $this->withHeaders($ownerHeaders)
            ->deleteJson('/api/cutinapp/events/bulk',['event_ids'=>[$owned['id'],$foreign['id']]])
            ->assertForbidden();

        $this->assertDatabaseHas('events',['id'=>$owned['id']]);
        $this->assertDatabaseHas('events',['id'=>$foreign['id']]);
    }

    public function test_bulk_delete_accepts_more_than_one_hundred_selected_events(): void
    {
        $user=$this->user('Produtor Exclusao Grande','bulk-delete-many@cutinapp.test');
        $headers=$this->headersFor($user);
        $application=Application::query()->where('slug','cutinapp')->firstOrFail();
        $production=$this->withHeaders($headers)->postJson('/api/cutinapp/productions',['name'=>'Produção Exclusão Grande'])->assertCreated()->json('production');

        $now=now();
        $rows=[];
        for($index=1;$index<=105;$index++){
            $rows[]=[
                'app_id'=>$application->id,
                'app_slug'=>'cutinapp',
                'production_id'=>$production['id'],
                'title'=>'Evento Selecionado '.$index,
                'slug'=>'evento-selecionado-'.$index.'-'.Str::random(8),
                'description'=>'Evento criado para validar exclusão acima de cem registros.',
                'address'=>'Rua Exclusão, 100',
                'city'=>'Goiânia',
                'uf'=>'GO',
                'start_date'=>$now->copy()->addDays(10)->addMinutes($index),
                'end_date'=>$now->copy()->addDays(10)->addMinutes($index)->addHours(3),
                'is_published'=>false,
                'is_cancelled'=>false,
                'created_at'=>$now,
                'updated_at'=>$now,
            ];
        }
        DB::table('events')->insert($rows);
        $ids=DB::table('events')->where('production_id',$production['id'])->pluck('id')->map(fn($id)=>(int)$id)->values()->all();

        $this->assertCount(105,$ids);
        $this->withHeaders($headers)
            ->deleteJson('/api/cutinapp/events/bulk',['event_ids'=>$ids])
            ->assertOk()
            ->assertJsonPath('deleted',105);

        $this->assertSame(0,DB::table('events')->whereIn('id',$ids)->count());
    }

    public function test_management_counts_only_inventory_that_is_currently_sellable(): void
    {
        $user=$this->user('Produtor Estoque','inventory-owner@cutinapp.test');$headers=$this->headersFor($user);$application=Application::query()->where('slug','cutinapp')->firstOrFail();
        $production=$this->withHeaders($headers)->postJson('/api/cutinapp/productions',['name'=>'Produção Estoque'])->assertCreated()->json('production');
        $event=$this->withHeaders($headers)->postJson('/api/cutinapp/events',['production_id'=>$production['id'],'title'=>'Evento Estoque','description'=>'Teste de inventário vendável.','address'=>'Rua Estoque, 10','city'=>'São Paulo','uf'=>'SP','start_date'=>now()->addDays(2)->format('Y-m-d H:i:s'),'end_date'=>now()->addDays(2)->addHours(3)->format('Y-m-d H:i:s')])->assertCreated()->json('event');
        $ticket=$this->withHeaders($headers)->postJson('/api/cutinapp/courtesies',['event_id'=>$event['id'],'name'=>'Lote Estoque','quantity'=>2])->assertCreated()->json('ticket');
        $orderId=DB::table('commerce_orders')->insertGetId(['app_id'=>$application->id,'public_id'=>(string)Str::uuid(),'event_id'=>$event['id'],'production_id'=>$production['id'],'user_id'=>$user->id,'status'=>'pending','currency'=>'BRL','subtotal'=>0,'platform_fee'=>0,'processor_fee'=>0,'discount_amount'=>0,'total'=>0,'producer_net'=>0,'expires_at'=>now()->addMinutes(20),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('inventory_reservations')->insert(['app_id'=>$application->id,'order_id'=>$orderId,'type'=>'ticket','ticket_id'=>$ticket['id'],'quantity'=>2,'expires_at'=>now()->addMinutes(20),'created_at'=>now(),'updated_at'=>now()]);

        $this->withHeaders($headers)->getJson('/api/cutinapp/events/mine')->assertOk()->assertJsonPath('events.data.0.available_tickets_count',0);
        $this->withHeaders($headers)->getJson('/api/cutinapp/events/show/'.$event['id'])->assertOk()->assertJsonPath('event.available_tickets_count',0);

        DB::table('inventory_reservations')->where('order_id',$orderId)->update(['expires_at'=>now()->subMinute(),'updated_at'=>now()]);
        $this->withHeaders($headers)->getJson('/api/cutinapp/events/mine')->assertOk()->assertJsonPath('events.data.0.available_tickets_count',1);
    }

    public function test_same_day_future_event_is_allowed_but_past_and_invalid_dates_are_rejected(): void
    {
        $this->travelTo(Carbon::create(2026, 9, 1, 12, 0, 0, 'America/Sao_Paulo'));

        try {
            $user=$this->user('Produtor Datas','event-dates@cutinapp.test');$headers=$this->headersFor($user);$productionId=$this->withHeaders($headers)->postJson('/api/cutinapp/productions',['name'=>'Produção Datas'])->assertCreated()->json('production.id');
            $base=['production_id'=>$productionId,'title'=>'Evento Datas','description'=>'Validação de datas.','address'=>'Rua Datas, 1','city'=>'São Paulo','uf'=>'SP'];
            $sameDay=$this->withHeaders($headers)->postJson('/api/cutinapp/events',$base+['start_date'=>now()->addHours(2)->format('Y-m-d H:i:s'),'end_date'=>now()->addHours(4)->format('Y-m-d H:i:s')])->assertCreated()->assertJsonPath('event.is_published',false);
            $eventId=(int)$sameDay->json('event.id');
            $this->withHeaders($headers)->postJson('/api/cutinapp/courtesies',['event_id'=>$eventId,'name'=>'Cortesia Hoje','quantity'=>5])->assertCreated();
            $this->withHeaders($headers)->postJson("/api/cutinapp/events/{$eventId}/publish")->assertOk()->assertJsonPath('event.is_published',true);
            $this->getJson('/api/cutinapp/events?period=today')->assertOk()->assertJsonFragment(['id'=>$eventId,'title'=>'Evento Datas']);
            $this->withHeaders($headers)->postJson('/api/cutinapp/events',$base+['title'=>'Evento Passado','start_date'=>now()->subHour()->format('Y-m-d H:i:s'),'end_date'=>now()->addHour()->format('Y-m-d H:i:s')])->assertStatus(422)->assertJsonPath('errors.start_date.0','O horário de início do evento precisa estar no futuro.');
            $same=now()->addDays(2)->format('Y-m-d H:i:s');$this->withHeaders($headers)->postJson('/api/cutinapp/events',$base+['start_date'=>$same,'end_date'=>$same])->assertStatus(422)->assertJsonPath('errors.end_date.0','O término do evento precisa ser posterior ao início.');
            $this->withHeaders($headers)->postJson('/api/cutinapp/events',$base+['start_date'=>'data-invalida','end_date'=>now()->addDays(2)->format('Y-m-d H:i:s')])->assertStatus(422)->assertJsonPath('errors.start_date.0','Informe uma data de início válida.');
            $this->withHeaders($headers)->postJson('/api/cutinapp/events',$base+['google_maps_url'=>'maps-sem-protocolo','start_date'=>now()->addDays(2)->format('Y-m-d H:i:s'),'end_date'=>now()->addDays(2)->addHours(2)->format('Y-m-d H:i:s')])->assertStatus(422);
        } finally {
            $this->travelBack();
        }
    }

    public function test_event_cannot_be_created_with_production_from_another_application(): void
    {
        $user=$this->user('Produtor Isolado','event-isolation@cutinapp.test');$headers=$this->headersFor($user);$otherApp=Application::query()->firstOrCreate(['slug'=>'event-other-app'],['name'=>'Event Other App','is_active'=>true]);$otherProduction=Production::create(['app_id'=>$otherApp->id,'app_slug'=>'event-other-app','user_id'=>$user->id,'name'=>'Produção Externa','slug'=>'producao-externa-evento','is_published'=>true,'is_cancelled'=>false]);
        $this->withHeaders($headers)->postJson('/api/cutinapp/events',['production_id'=>$otherProduction->id,'title'=>'Evento Indevido','description'=>'Não deve ser criado.','address'=>'Rua X','start_date'=>now()->addDays(2)->format('Y-m-d H:i:s'),'end_date'=>now()->addDays(2)->addHour()->format('Y-m-d H:i:s')])->assertNotFound();$this->assertDatabaseMissing('events',['title'=>'Evento Indevido']);
    }

    private function headersFor(User $user):array{return['Authorization'=>'Bearer '.JWTAuth::fromUser($user),'X-Peter-App'=>'cutinapp'];}
    private function user(string $name,string $email):User{return User::create(['first_name'=>$name,'email'=>$email,'user_name'=>strtolower(str_replace(' ','-',$name)).'-'.substr(md5($email),0,8),'password'=>Hash::make('Test1234!'),'email_verified_at'=>now()]);}
}