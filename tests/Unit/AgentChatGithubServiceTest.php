<?php

namespace Tests\Unit;

use App\Services\AgentChatGithubService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentChatGithubServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config()->set('services.agent_chat', [
            'repository' => 'petertecnetdev/petertecnet.com.br',
            'branch' => 'main',
            'path' => '.agents/AGENT_CHAT.md',
            'token' => null,
            'timeout' => 3,
        ]);
    }

    public function test_it_reads_and_parses_the_shared_chat(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.github.com/*' => Http::response([
                'sha' => 'chat-sha',
                'content' => base64_encode($this->history()),
            ]),
        ]);

        $snapshot = app(AgentChatGithubService::class)->snapshot(true);

        $this->assertCount(1, $snapshot['messages']);
        $this->assertSame('Pedro', $snapshot['messages'][0]['author']);
        $this->assertSame('@todos', $snapshot['messages'][0]['to']);
        $this->assertSame('REQUEST', $snapshot['messages'][0]['status']);
        $this->assertFalse($snapshot['write_enabled']);
    }

    public function test_it_appends_without_overwriting_existing_messages(): void
    {
        config()->set('services.agent_chat.token', 'test-token');

        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push([
                'sha' => 'chat-sha',
                'content' => base64_encode($this->history()),
            ], 200)
            ->push([
                'content' => ['sha' => 'new-content-sha'],
                'commit' => ['sha' => 'new-commit-sha'],
            ], 200);

        $result = app(AgentChatGithubService::class)->append([
            'message' => 'Verifiquem o checkout antes do próximo deploy.',
            'to' => '@todos',
            'type' => 'REQUEST',
        ]);

        $this->assertSame('new-commit-sha', $result['commit_sha']);
        $this->assertSame('Verifiquem o checkout antes do próximo deploy.', $result['message']['message']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT') {
                return true;
            }

            $body = $request->data();
            $content = base64_decode((string) ($body['content'] ?? ''), true);

            return is_string($content)
                && str_contains($content, 'Mensagem antiga')
                && str_contains($content, 'Verifiquem o checkout antes do próximo deploy.')
                && ($body['sha'] ?? null) === 'chat-sha';
        });
    }

    private function history(): string
    {
        return <<<'MD'
# Peter Tecnet — Agent Chat

---

### 2026-09-18 13:00 BRT — Pedro — REQUEST
**Para:** @todos
**Assunto:** Teste

Mensagem antiga

**Repo:** petertecnetdev/petertecnet.com.br
**Branch:** main
**Commit/PR:** n/a
**Status:** REQUEST
---
MD;
    }
}
