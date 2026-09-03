<?php

namespace Tests;

use App\Models\Application;
use App\Services\ProducerAgreementService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('applications')) {
            return;
        }

        foreach (array_keys((array) config('platform.applications', [])) as $slug) {
            Application::query()->firstOrCreate(
                ['slug' => (string) $slug],
                [
                    'name' => Str::headline((string) $slug),
                    'is_active' => true,
                    'self_service_access' => true,
                ]
            );
        }
    }

    /**
     * Return an application fixture without duplicating the application rows
     * provisioned from config/platform.php by the shared test harness.
     */
    protected function applicationFixture(string $slug, array $attributes = []): Application
    {
        unset($attributes['slug']);
        $configured = (array) config('platform.applications.' . $slug, []);

        return Application::query()->updateOrCreate(
            ['slug' => $slug],
            array_merge([
                'name' => Str::headline($slug),
                'url' => $configured['url'] ?? null,
                'is_active' => true,
                'self_service_access' => true,
            ], $attributes)
        );
    }

    /**
     * Laravel's HTTP test kernel reuses the same application instance between
     * requests, while production PHP-FPM requests resolve authentication from
     * scratch. Reset both Laravel guards and JWTAuth's cached token before and
     * after every simulated request, then explicitly bind the API guard to the
     * Bearer token from the request being executed.
     *
     * Applications may require a signed producer agreement before event
     * creation. Legacy feature tests predate that requirement, so the shared
     * harness provisions a realistic acceptance row for event-creation
     * requests. Tests that explicitly verify the unsigned behavior can opt
     * out with the X-Test-Unsigned-Contract header.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->resetAuthenticationState();
        $this->provisionProducerAgreementFixture($method, $uri, $parameters, $server, $content);
        $this->bindRequestBearerToken($server);

        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->resetAuthenticationState();
        }
    }

    protected function tearDown(): void
    {
        $this->resetAuthenticationState();
        parent::tearDown();
    }

    private function resetAuthenticationState(): void
    {
        if (isset($this->app) && $this->app->bound('auth')) {
            $this->app['auth']->forgetGuards();
        }

        try {
            JWTAuth::unsetToken();
        } catch (\Throwable) {
            // Authentication may not have been bootstrapped for this test yet.
        }
    }

    private function bindRequestBearerToken(array $server): void
    {
        $authorization = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['Authorization'] ?? '');
        if (! preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches)) {
            return;
        }

        $token = trim((string) ($matches[1] ?? ''));
        if ($token === '' || ! isset($this->app) || ! $this->app->bound('auth')) {
            return;
        }

        $guard = $this->app['auth']->guard('api');
        if (method_exists($guard, 'setToken')) {
            $guard->setToken($token);
        }

        try {
            JWTAuth::setToken($token);
        } catch (\Throwable) {
            // The guard is authoritative; the facade binding is only defensive.
        }
    }

    private function provisionProducerAgreementFixture($method, $uri, $parameters, $server, $content): void
    {
        if (strtoupper((string) $method) !== 'POST') {
            return;
        }

        $path = (string) parse_url((string) $uri, PHP_URL_PATH);
        $isLegacyEventCreate = preg_match('#^/api/[^/]+/events$#', $path) === 1;
        $isV1EventCreate = preg_match('#^/api/v1/apps/[^/]+/events$#', $path) === 1;
        if (! $isLegacyEventCreate && ! $isV1EventCreate) {
            return;
        }

        if (! empty($server['HTTP_X_TEST_UNSIGNED_CONTRACT'])) {
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
        if ($productionId <= 0 || ! Schema::hasTable('contract_acceptances')) {
            return;
        }

        $production = DB::table('productions')->where('id', $productionId)->first();
        if (! $production || empty($production->user_id)) {
            return;
        }

        $row = [
            'production_id' => $productionId,
            'user_id' => (int) $production->user_id,
            'contract_version' => ProducerAgreementService::VERSION,
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
        ];

        if (Schema::hasColumn('contract_acceptances', 'app_id')) {
            $row['app_id'] = $production->app_id ?? null;
        }

        DB::table('contract_acceptances')->insertOrIgnore($row);
    }
}
