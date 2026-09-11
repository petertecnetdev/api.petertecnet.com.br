<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\EventPass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappProducerEventMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_producer_listing_contains_aggregated_operational_metrics(): void
    {
        $user=User::create([
            'first_name'=>'Produtor Métricas',
            'email'=>'event-metrics@cutinapp.test',
            'user_name'=>'event-metrics',
            'password'=>Hash::make('Test1234!'),
            'email_verified_at'=>now(),
        ]);
        $headers=[
            'Authorization'=>'Bearer '.JWTAuth::fromUser($user),
            'X-Peter-App'=>'cutinapp',
        ];
        $application=Application::query()->where('slug','cutinapp')->firstOrFail();

        $production=$this->withHeaders($headers)
            ->postJson('/api/cutinapp/productions',['name'=>'Produção Métricas'])
            ->assertCreated()
            ->json('production');

        $event=$this->withHeaders($headers)
            ->postJson('/api/cutinapp/events',[
                'production_id'=>$production['id'],
                'title'=>'Evento Métricas',
                'description'=>'Validação das métricas operacionais.',
                'address'=>'Rua Métricas, 10',
                'venue'=>'Espaço Métricas',
                'city'=>'Goiânia',
                'uf'=>'GO',
                'start_date'=>now()->addDays(5)->format('Y-m-d H:i:s'),
                'end_date'=>now()->addDays(5)->addHours(4)->format('Y-m-d H:i:s'),
            ])
            ->assertCreated()
            ->json('event');

        $ticket=$this->withHeaders($headers)
            ->postJson('/api/cutinapp/courtesies',[
                'event_id'=>$event['id'],
                'name'=>'Lote Métricas',
                'quantity'=>20,
            ])
            ->assertCreated()
            ->json('ticket');

        $orderId=DB::table('commerce_orders')->insertGetId([
            'app_id'=>$application->id,
            'public_id'=>(string)Str::uuid(),
            'event_id'=>$event['id'],
            'production_id'=>$production['id'],
            'user_id'=>$user->id,
            'status'=>'paid',
            'currency'=>'BRL',
            'subtotal'=>100,
            'platform_fee'=>0,
            'processor_fee'=>0,
            'discount_amount'=>0,
            'total'=>100,
            'producer_net'=>100,
            'payment_method'=>'pix',
            'paid_at'=>now(),
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);

        DB::table('commerce_order_items')->insert([
            'app_id'=>$application->id,
            'order_id'=>$orderId,
            'type'=>'ticket',
            'ticket_id'=>$ticket['id'],
            'name'=>'Lote Métricas',
            'unit_price'=>50,
            'quantity'=>2,
            'subtotal'=>100,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);

        $pass=EventPass::create([
            'ticket_id'=>$ticket['id'],
            'event_id'=>$event['id'],
            'user_id'=>$user->id,
            'holder_name'=>'Produtor Métricas',
            'holder_email'=>$user->email,
            'token'=>(string)Str::uuid(),
            'status'=>'issued',
        ]);
        DB::table('event_passes')->where('id',$pass->id)->update([
            'checked_in_at'=>now(),
            'checked_in_by'=>$user->id,
            'updated_at'=>now(),
        ]);

        $this->withHeaders($headers)
            ->getJson('/api/cutinapp/events/mine')
            ->assertOk()
            ->assertJsonPath('events.data.0.operational_metrics.paid_orders_count',1)
            ->assertJsonPath('events.data.0.operational_metrics.gross_sales',100)
            ->assertJsonPath('events.data.0.operational_metrics.tickets_sold',2)
            ->assertJsonPath('events.data.0.operational_metrics.passes_issued',1)
            ->assertJsonPath('events.data.0.operational_metrics.ticket_capacity',20)
            ->assertJsonPath('events.data.0.operational_metrics.tickets_remaining',19)
            ->assertJsonPath('events.data.0.operational_metrics.checked_in_count',1)
            ->assertJsonPath('events.data.0.operational_metrics.agenda_active',false);
    }
}
