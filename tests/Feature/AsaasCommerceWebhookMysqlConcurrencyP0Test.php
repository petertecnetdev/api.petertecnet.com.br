<?php

namespace Tests\Feature;

use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use App\Models\User;
use App\Services\AsaasCommerceWebhookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Gate separado: a suite SQLite nao simula duas conexoes InnoDB concorrentes.
 * Este teste so executa no MySQL efemero do GitHub Actions, nunca em producao.
 */
final class AsaasCommerceWebhookMysqlConcurrencyP0Test extends TestCase
{
    public function test_duas_conexoes_mysql_liquidam_um_unico_webhook_pix_sem_duplicar_ledger_ou_qr(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concorrencia real exige MySQL/InnoDB; SQLite nao equivale.');
        }

        // O teste cria registros COMMITADOS visiveis a processos externos.
        // Falhar antes de qualquer escrita se o banco nao for o CI descartavel.
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('true', getenv('GITHUB_ACTIONS'));
        $this->assertSame('cutinapp_ci_test', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertTrue(function_exists('proc_open'), 'O runner precisa suportar processos PHP independentes.');

        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        Mail::fake();
        [$payment, $order, $item, $event, $remote] = $this->fixtureConcorrente();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('pending', $order->status);

        $directory = sys_get_temp_dir() . '/asaas-mysql-concor-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700), 'Nao foi possivel criar diretorio temporario.');
        $script = $directory . '/worker.php';
        $markerA = $directory . '/primeiro-reservou';
        $markerB = $directory . '/segundo-tentou';
        $resultA = $directory . '/primeiro.json';
        $resultB = $directory . '/segundo.json';

        // Cada processo inicia seu proprio Laravel/PDO MySQL e simula somente
        // o GET Asaas; a concorrencia e as transacoes sao REAIS no InnoDB.
        $worker = <<<'PHP'
<?php
require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\AsaasCommerceWebhookService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

[$script, $mode, $reserved, $attempted, $resultFile, $eventEncoded, $remoteEncoded] = $argv;
try {
    if (DB::connection()->getDriverName() !== 'mysql'
        || DB::selectOne('SELECT DATABASE() AS name')->name !== 'cutinapp_ci_test'
        || getenv('GITHUB_ACTIONS') !== 'true') {
        throw new RuntimeException('Worker fora do MySQL efemero autorizado.');
    }
    config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
    DB::statement('SET SESSION innodb_lock_wait_timeout = 12');
    $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $event = json_decode(base64_decode($eventEncoded, true), true, 512, JSON_THROW_ON_ERROR);
    $remote = json_decode(base64_decode($remoteEncoded, true), true, 512, JSON_THROW_ON_ERROR);
    Http::fake(fn () => Http::response($remote, 200));
    Mail::fake();

    if ($mode === 'primeiro') {
        DB::listen(function (QueryExecuted $query) use ($reserved, $attempted): void {
            if (! str_contains(strtolower($query->sql), 'financial_webhook_events')
                || ! str_contains(strtolower($query->sql), 'insert')) {
                return;
            }
            file_put_contents($reserved, (string) microtime(true));
            $deadline = microtime(true) + 10;
            while (! file_exists($attempted) && microtime(true) < $deadline) {
                usleep(20000);
            }
            if (! file_exists($attempted)) {
                throw new RuntimeException('Segundo processo nao iniciou a disputa pelo recibo.');
            }
            // Mantem o INSERT do primeiro ainda nao COMMITADO enquanto o
            // segundo processo tenta inserir a mesma chave unica InnoDB.
            usleep(700000);
        });
    } else {
        $deadline = microtime(true) + 10;
        while (! file_exists($reserved) && microtime(true) < $deadline) {
            usleep(20000);
        }
        if (! file_exists($reserved)) {
            throw new RuntimeException('Primeiro processo nao reservou o recibo.');
        }
        file_put_contents($attempted, (string) microtime(true));
    }

    DB::transaction(function () use ($event): void {
        app(AsaasCommerceWebhookService::class)->process($event);
    }, 1);
    file_put_contents($resultFile, json_encode([
        'ok' => true, 'connection_id' => $connectionId, 'mode' => $mode,
    ], JSON_THROW_ON_ERROR));
} catch (\Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'ok' => false, 'error' => get_class($exception) . ': ' . $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
PHP;

        file_put_contents($script, $worker);
        $processes = [];
        $pipes = [];

        try {
            foreach (['primeiro' => $resultA, 'segundo' => $resultB] as $mode => $resultFile) {
                $command = [
                    PHP_BINARY, $script, $mode, $markerA, $markerB, $resultFile,
                    base64_encode(json_encode($event, JSON_THROW_ON_ERROR)),
                    base64_encode(json_encode($remote, JSON_THROW_ON_ERROR)),
                ];
                $process = proc_open(
                    $command,
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $childPipes,
                    base_path()
                );
                $this->assertTrue(is_resource($process), 'Falha ao iniciar worker PHP ' . $mode);
                fclose($childPipes[0]);
                $processes[$mode] = $process;
                $pipes[$mode] = $childPipes;
            }

            $results = [];
            foreach (['primeiro' => $resultA, 'segundo' => $resultB] as $mode => $resultFile) {
                $stdout = stream_get_contents($pipes[$mode][1]);
                $stderr = stream_get_contents($pipes[$mode][2]);
                fclose($pipes[$mode][1]);
                fclose($pipes[$mode][2]);
                unset($pipes[$mode]);
                $exit = proc_close($processes[$mode]);
                unset($processes[$mode]);

                $this->assertFileExists($resultFile, 'Worker sem resultado: ' . $mode . ' ' . $stderr);
                $result = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(0, $exit, $mode . ': ' . ($result['error'] ?? $stderr . $stdout));
                $this->assertTrue($result['ok'], $mode . ': ' . ($result['error'] ?? 'erro desconhecido'));
                $results[$mode] = $result;
            }

            $this->assertNotSame(
                $results['primeiro']['connection_id'],
                $results['segundo']['connection_id'],
                'Dois processos devem possuir CONNECTION_ID() MySQL distintos.'
            );
            $this->assertFileExists($markerA);
            $this->assertFileExists($markerB);
            $this->assertDatabaseHas('commerce_orders', ['id' => $order->id, 'status' => 'paid']);
            $this->assertDatabaseHas('commerce_payments', ['id' => $payment->id, 'status' => 'paid']);
            $this->assertSame(1, DB::table('financial_webhook_events')
                ->where('provider', 'asaas')->where('event_id', $event['id'])->count());
            $this->assertNotNull(DB::table('financial_webhook_events')
                ->where('provider', 'asaas')->where('event_id', $event['id'])->value('processed_at'));

            $this->assertEqualsCanonicalizing(
                ['gross_sale', 'platform_fee', 'producer_credit'],
                DB::table('ledger_entries')->where('payment_id', $payment->id)->pluck('type')->all()
            );
            $this->assertSame(3, DB::table('ledger_entries')->where('payment_id', $payment->id)->count());
            $this->assertSame(1, DB::table('event_passes')
                ->where('commerce_order_item_id', $item->id)->where('status', 'issued')->count());
            $token = (string) DB::table('event_passes')
                ->where('commerce_order_item_id', $item->id)->value('token');
            $this->assertTrue(str_starts_with($token, 'PASS-'));

            // Repetir mesmo event_id e outro evento legitimo da mesma cobranca:
            // 1 GET no evento novo, nenhum novo settlement, ledger ou QR.
            Http::fake(fn () => Http::response($remote, 200));
            $service = app(AsaasCommerceWebhookService::class);
            $service->process($event);
            $service->process(array_replace($event, ['id' => $event['id'] . '-replay']));
            Http::assertSentCount(1);
            Http::assertNotSent(fn ($request) => $request->method() === 'POST');
            $this->assertSame(3, DB::table('ledger_entries')->where('payment_id', $payment->id)->count());
            $this->assertSame(1, DB::table('event_passes')->where('commerce_order_item_id', $item->id)->count());
            $this->assertSame($token, (string) DB::table('event_passes')
                ->where('commerce_order_item_id', $item->id)->value('token'));
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            foreach ($pipes as $childPipes) {
                foreach ($childPipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    /** Dados artificiais em schema efemero, jamais pagamento/ingresso real. */
    private function fixtureConcorrente(): array
    {
        $suffix = bin2hex(random_bytes(6));
        $appId = (int) DB::table('applications')->where('slug', 'cutinapp')->value('id');
        if ($appId <= 0) {
            $appId = DB::table('applications')->insertGetId([
                'name' => 'Cutinapp Teste', 'slug' => 'cutinapp', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $buyer = User::create([
            'first_name' => 'Comprador', 'last_name' => 'Concorrencia',
            'email' => 'concorrencia-' . $suffix . '@cutinapp.test',
            'user_name' => 'concorrencia-' . $suffix,
            'password' => Hash::make('Test1234!'), 'email_verified_at' => now(),
        ]);
        $productionId = DB::table('establishments')->insertGetId([
            'app_id' => $appId, 'app_slug' => 'cutinapp',
            'name' => 'Producao Concorrencia ' . $suffix,
            'fantasy' => 'Producao Concorrencia ' . $suffix,
            'slug' => 'producao-concor-' . $suffix,
            'category' => 'production', 'type' => 'production',
            'establishment_type' => 'production', 'city' => 'Sao Paulo',
            'user_id' => $buyer->id, 'created_by' => $buyer->id, 'updated_by' => $buyer->id,
            'ownership_status' => null, 'listing_source' => 'legacy_organizer',
            'verification_status' => 'legacy_owner_record',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $eventId = DB::table('events')->insertGetId([
            'app_id' => $appId, 'app_slug' => 'cutinapp',
            'production_id' => $productionId, 'title' => 'Evento Concorrencia ' . $suffix,
            'created_by_user_id' => $buyer->id,
            'ownership_status' => null, 'listing_source' => 'legacy_organizer',
            'verification_status' => 'legacy_owner_record',
            'slug' => 'evento-concor-' . $suffix,
            'start_date' => now()->addDay(), 'end_date' => now()->addDay()->addHours(4),
            'is_published' => true, 'is_private' => false, 'is_cancelled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ticketId = DB::table('tickets')->insertGetId([
            'app_id' => $appId, 'app_slug' => 'cutinapp',
            'event_id' => $eventId, 'name' => 'Inteira Concorrencia',
            'quantity' => 10, 'price' => 20.00,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $publicId = 'pedido-asaas-concor-' . $suffix;
        $providerId = 'pay-asaas-concor-' . $suffix;
        $order = CommerceOrder::create([
            'app_id' => $appId, 'public_id' => $publicId,
            'event_id' => $eventId, 'production_id' => $productionId,
            'user_id' => $buyer->id, 'status' => 'pending', 'currency' => 'BRL',
            'subtotal' => 20.00, 'platform_fee' => 2.00, 'processor_fee' => 0,
            'discount_amount' => 0, 'total' => 20.00, 'producer_net' => 18.00,
            'payment_method' => 'pix', 'metadata' => ['settlement_mode' => 'platform_collection'],
        ]);
        $item = \App\Models\CommerceOrderItem::create([
            'app_id' => $appId, 'order_id' => $order->id,
            'type' => 'ticket', 'ticket_id' => $ticketId,
            'name' => 'Inteira Concorrencia', 'unit_price' => 20.00,
            'quantity' => 1, 'subtotal' => 20.00,
        ]);
        $payment = CommercePayment::create([
            'app_id' => $appId, 'order_id' => $order->id,
            'provider' => 'asaas', 'method' => 'pix', 'status' => 'pending',
            'provider_payment_id' => $providerId, 'provider_txid' => null,
            'idempotency_key' => 'asaas-concor-' . $suffix,
            'amount' => 20.00, 'provider_fee' => 0,
        ]);
        $remote = [
            'id' => $providerId, 'externalReference' => $publicId,
            'value' => 20.00, 'billingType' => 'PIX', 'status' => 'RECEIVED',
        ];
        $event = [
            'id' => 'evt-asaas-concor-' . $suffix, 'event' => 'PAYMENT_RECEIVED',
            'payment' => $remote,
        ];

        return [$payment, $order, $item, $event, $remote];
    }
}
