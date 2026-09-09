<?php

namespace Tests\Unit;

use App\Services\DeliveryEffectService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DeliveryEffectServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('delivery_effects');
        Schema::create('delivery_effects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id');
            $table->char('dedupe_key', 64)->unique();
            $table->string('aggregate_type', 80);
            $table->string('aggregate_id', 191);
            $table->string('effect_key', 120);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function test_completed_effect_is_not_executed_twice(): void
    {
        $service = app(DeliveryEffectService::class);
        $runs = 0;

        $first = $service->run(7, 'commerce_order', 42, 'buyer_email', function () use (&$runs) { $runs++; });
        $second = $service->run(7, 'commerce_order', 42, 'buyer_email', function () use (&$runs) { $runs++; });

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(1, $runs);
        $this->assertSame('completed', DB::table('delivery_effects')->value('status'));
        $this->assertSame(1, (int) DB::table('delivery_effects')->value('attempts'));
    }

    public function test_failed_effect_is_persisted_and_can_be_retried(): void
    {
        $service = app(DeliveryEffectService::class);
        $runs = 0;

        try {
            $service->run(7, 'commerce_order', 43, 'buyer_email', function () use (&$runs) {
                $runs++;
                throw new RuntimeException('mail transport unavailable');
            });
            $this->fail('Expected delivery failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('mail transport unavailable', $e->getMessage());
        }

        $this->assertSame('failed', DB::table('delivery_effects')->value('status'));
        $this->assertTrue($service->hasPendingForAggregate(7, 'commerce_order', 43));

        $this->assertTrue($service->run(7, 'commerce_order', 43, 'buyer_email', function () use (&$runs) { $runs++; }));
        $this->assertSame(2, $runs);
        $this->assertFalse($service->hasPendingForAggregate(7, 'commerce_order', 43));
        $this->assertSame(2, (int) DB::table('delivery_effects')->value('attempts'));
    }

    public function test_fresh_processing_claim_is_not_stolen(): void
    {
        $service = app(DeliveryEffectService::class);
        $runs = 0;
        $dedupeKey = hash('sha256', implode("\0", [7, 'commerce_order', '44', 'buyer_email']));

        DB::table('delivery_effects')->insert([
            'app_id'=>7,'dedupe_key'=>$dedupeKey,'aggregate_type'=>'commerce_order','aggregate_id'=>'44',
            'effect_key'=>'buyer_email','status'=>'processing','attempts'=>1,'claimed_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        $this->assertFalse($service->run(7, 'commerce_order', 44, 'buyer_email', function () use (&$runs) { $runs++; }));
        $this->assertSame(0, $runs);
        $this->assertSame(1, (int) DB::table('delivery_effects')->value('attempts'));
    }

    public function test_stale_processing_claim_is_recovered(): void
    {
        $service = app(DeliveryEffectService::class);
        $runs = 0;
        $dedupeKey = hash('sha256', implode("\0", [7, 'commerce_order', '45', 'buyer_email']));

        DB::table('delivery_effects')->insert([
            'app_id'=>7,'dedupe_key'=>$dedupeKey,'aggregate_type'=>'commerce_order','aggregate_id'=>'45',
            'effect_key'=>'buyer_email','status'=>'processing','attempts'=>1,'claimed_at'=>now()->subMinutes(15),
            'created_at'=>now()->subMinutes(15),'updated_at'=>now()->subMinutes(15),
        ]);

        $this->assertTrue($service->run(7, 'commerce_order', 45, 'buyer_email', function () use (&$runs) { $runs++; }));
        $this->assertSame(1, $runs);
        $this->assertSame(2, (int) DB::table('delivery_effects')->value('attempts'));
        $this->assertSame('completed', DB::table('delivery_effects')->value('status'));
    }
}
