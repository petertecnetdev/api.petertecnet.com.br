<?php

namespace Tests;

use App\Services\CutinappProducerContractService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * The HTTP test kernel reuses the same application instance between
     * requests. JWT guards cache the resolved user, while real PHP-FPM
     * requests start with a fresh guard. Reset guards before each simulated
     * request so every Authorization header is authenticated independently.
     *
     * Cutinapp now requires a signed producer contract before event creation.
     * Legacy feature tests predate that requirement, so the shared test
     * harness provisions a realistic acceptance row for event-creation
     * requests. Tests that explicitly verify the unsigned-contract behavior
     * can opt out with the X-Test-Unsigned-Contract header.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if (isset($this->app) && $this->app->bound('auth')) {
            $this->app['auth']->forgetGuards();
        }

        $this->provisionCutinappContractFixture($method, $uri, $parameters, $server, $content);

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function provisionCutinappContractFixture($method, $uri, $parameters, $server, $content): void
    {
        if (strtoupper((string) $method) !== 'POST' || parse_url((string) $uri, PHP_URL_PATH) !== '/api/cutinapp/events') {
            return;
        }

        if (!empty($server['HTTP_X_TEST_UNSIGNED_CONTRACT'])) {
            return;
        }

        $payload = is_array($parameters) ? $parameters : [];
        if (empty($payload['production_id']) && is_string($content) && $content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $productionId = (int) ($payload['production_id'] ?? 0);
        if ($productionId <= 0 || !Schema::hasTable('cutinapp_producer_contract_acceptances')) {
            return;
        }

        $production = DB::table('productions')->where('id', $productionId)->first();
        if (!$production || empty($production->user_id)) {
            return;
        }

        DB::table('cutinapp_producer_contract_acceptances')->insertOrIgnore([
            'production_id' => $productionId,
            'user_id' => (int) $production->user_id,
            'contract_version' => CutinappProducerContractService::VERSION,
            'contract_hash' => hash('sha256', 'test-contract-' . $productionId),
            'contract_snapshot' => 'Contrato de teste aceito automaticamente pela infraestrutura de testes.',
            'signer_name' => 'Assinante de Teste',
            'signer_document' => '00000000000',
            'signer_role' => 'test',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
