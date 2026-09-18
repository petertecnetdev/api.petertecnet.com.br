<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AgentChatGithubService
{
    private string $repository;
    private string $branch;
    private string $path;
    private string $token;
    private int $timeout;

    public function __construct()
    {
        $this->repository = (string) config('services.agent_chat.repository', 'petertecnetdev/petertecnet.com.br');
        $this->branch = (string) config('services.agent_chat.branch', 'main');
        $this->path = (string) config('services.agent_chat.path', '.agents/AGENT_CHAT.md');
        $this->token = trim((string) config('services.agent_chat.token', ''));
        $this->timeout = max(3, (int) config('services.agent_chat.timeout', 12));
    }

    public function snapshot(bool $fresh = false): array
    {
        $cacheKey = 'agent-chat:github:' . sha1($this->repository . ':' . $this->branch . ':' . $this->path);

        $file = $fresh
            ? $this->fetchRemote()
            : Cache::remember($cacheKey, now()->addSeconds(5), fn () => $this->fetchRemote());

        return [
            'messages' => $this->parseMessages($file['content']),
            'write_enabled' => $this->writeEnabled(),
            'repository' => $this->repository,
            'branch' => $this->branch,
            'path' => $this->path,
            'source_url' => sprintf(
                'https://github.com/%s/blob/%s/%s',
                $this->repository,
                rawurlencode($this->branch),
                implode('/', array_map('rawurlencode', explode('/', trim($this->path, '/'))))
            ),
            'synced_at' => now()->toIso8601String(),
        ];
    }

    public function append(array $data, ?object $user = null): array
    {
        if (! $this->writeEnabled()) {
            throw new RuntimeException('A credencial de escrita do Agent Chat não está configurada.');
        }

        $message = trim((string) ($data['message'] ?? ''));
        if ($message === '') {
            throw new RuntimeException('A mensagem não pode ficar vazia.');
        }

        $author = $this->authorName($user);
        $to = $this->singleLine((string) ($data['to'] ?? '@todos')) ?: '@todos';
        $subject = $this->singleLine((string) ($data['subject'] ?? 'Mensagem do Admin Center')) ?: 'Mensagem do Admin Center';
        $type = strtoupper($this->singleLine((string) ($data['type'] ?? 'REQUEST')) ?: 'REQUEST');
        $allowedTypes = ['INFO', 'QUESTION', 'REQUEST', 'REVIEW', 'DONE', 'BLOCKED', 'START'];
        if (! in_array($type, $allowedTypes, true)) {
            $type = 'REQUEST';
        }

        $entry = $this->formatEntry(
            author: $author,
            to: $to,
            subject: $subject,
            message: $message,
            type: $type,
        );

        $lastError = null;

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $file = $this->fetchRemote();
            $content = rtrim($file['content']) . "\n\n" . $entry;

            $response = $this->client(true)->put($this->endpoint(), [
                'message' => sprintf('chore(agent-chat): message from %s', $author),
                'content' => base64_encode($content),
                'sha' => $file['sha'],
                'branch' => $this->branch,
            ]);

            if ($response->successful()) {
                Cache::forget($this->cacheKey());

                $payload = $response->json();
                $parsed = $this->parseMessages($content);

                return [
                    'message' => end($parsed) ?: null,
                    'commit_sha' => data_get($payload, 'commit.sha'),
                    'write_enabled' => true,
                    'synced_at' => now()->toIso8601String(),
                ];
            }

            $lastError = $response->status();

            if (! in_array($response->status(), [409, 422], true)) {
                $response->throw();
            }

            usleep(150000 * $attempt);
        }

        throw new RuntimeException('O Agent Chat foi alterado por outro agente durante o envio. Tente novamente.');
    }

    public function writeEnabled(): bool
    {
        return $this->token !== '';
    }

    private function fetchRemote(): array
    {
        $response = $this->client()->get($this->endpoint(), ['ref' => $this->branch]);
        $response->throw();

        $payload = $response->json();
        $encoded = preg_replace('/\s+/', '', (string) ($payload['content'] ?? ''));
        $content = base64_decode($encoded, true);

        if ($content === false) {
            throw new RuntimeException('Não foi possível decodificar o Agent Chat.');
        }

        return [
            'sha' => (string) ($payload['sha'] ?? ''),
            'content' => $content,
        ];
    }

    private function client(bool $requireToken = false): PendingRequest
    {
        if ($requireToken && $this->token === '') {
            throw new RuntimeException('A credencial de escrita do Agent Chat não está configurada.');
        }

        $request = Http::acceptJson()
            ->timeout($this->timeout)
            ->retry(2, 200, throw: false)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'Peter-Tecnet-Agent-Chat',
            ]);

        if ($this->token !== '') {
            $request = $request->withToken($this->token);
        }

        return $request;
    }

    private function endpoint(): string
    {
        $path = implode('/', array_map('rawurlencode', explode('/', trim($this->path, '/'))));

        return sprintf('https://api.github.com/repos/%s/contents/%s', $this->repository, $path);
    }

    private function cacheKey(): string
    {
        return 'agent-chat:github:' . sha1($this->repository . ':' . $this->branch . ':' . $this->path);
    }

    private function authorName(?object $user): string
    {
        if (! $user) {
            return 'Peter Tecnet Admin';
        }

        $name = trim(implode(' ', array_filter([
            (string) ($user->first_name ?? ''),
            (string) ($user->last_name ?? ''),
        ])));

        return $this->singleLine(
            $name
                ?: (string) ($user->user_name ?? '')
                ?: (string) ($user->name ?? '')
                ?: 'Peter Tecnet Admin'
        );
    }

    private function formatEntry(string $author, string $to, string $subject, string $message, string $type): string
    {
        $timestamp = now('America/Sao_Paulo')->format('Y-m-d H:i') . ' BRT';

        return sprintf(
            "### %s — %s — %s\n**Para:** %s\n**Assunto:** %s\n\n%s\n\n**Repo:** %s\n**Branch:** %s\n**Commit/PR:** n/a\n**Status:** %s\n---\n",
            $timestamp,
            $this->singleLine($author),
            $type,
            $to,
            $subject,
            $message,
            $this->repository,
            $this->branch,
            $type,
        );
    }

    private function parseMessages(string $content): array
    {
        $blocks = preg_split('/(?=^### )/m', $content) ?: [];
        $messages = [];

        $pattern = '/^### (?<timestamp>[^\r\n]+?)\s+—\s+(?<author>.+?)\s+—\s+(?<type>[A-Z_]+)\R'
            . '\*\*Para:\*\*\s*(?<to>[^\r\n]*)\R'
            . '\*\*Assunto:\*\*\s*(?<subject>[^\r\n]*)\R\R'
            . '(?<message>.*?)\R\R'
            . '\*\*Repo:\*\*\s*(?<repo>[^\r\n]*)\R'
            . '\*\*Branch:\*\*\s*(?<branch>[^\r\n]*)\R'
            . '\*\*Commit\/PR:\*\*\s*(?<commit>[^\r\n]*)\R'
            . '\*\*Status:\*\*\s*(?<status>[^\r\n]*)\R'
            . '---/su';

        foreach ($blocks as $block) {
            if (! str_starts_with($block, '### ')) {
                continue;
            }

            if (! preg_match($pattern, trim($block), $match)) {
                continue;
            }

            $messages[] = [
                'id' => substr(hash('sha256', trim($block)), 0, 20),
                'timestamp' => trim($match['timestamp']),
                'author' => trim($match['author']),
                'type' => trim($match['type']),
                'to' => trim($match['to']),
                'subject' => trim($match['subject']),
                'message' => trim($match['message']),
                'repo' => trim($match['repo']),
                'branch' => trim($match['branch']),
                'commit' => trim($match['commit']),
                'status' => trim($match['status']),
            ];
        }

        return $messages;
    }

    private function singleLine(string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    }
}
