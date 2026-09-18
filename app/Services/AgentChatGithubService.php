<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
        $file = $fresh
            ? $this->fetchRemote()
            : Cache::remember($this->cacheKey(), now()->addSeconds(5), fn () => $this->fetchRemote());

        $messages = $this->parseMessages($file['content']);
        $pending = $this->pendingRecords($file['content']);

        foreach ($pending as $record) {
            $parsed = $this->parseMessages((string) ($record['entry'] ?? ''));
            $message = $parsed[0] ?? null;

            if (! $message) {
                continue;
            }

            $message['id'] = 'pending-' . ($record['id'] ?? $message['id']);
            $message['sync_status'] = 'pending';
            $messages[] = $message;
        }

        return [
            'messages' => $messages,
            'write_enabled' => $this->writeEnabled(),
            'direct_write_enabled' => $this->directWriteEnabled(),
            'delivery_mode' => $this->directWriteEnabled() ? 'direct' : 'github_actions_sync',
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

        if (! $this->directWriteEnabled()) {
            return $this->queueForGithubActions(
                author: $author,
                to: $to,
                subject: $subject,
                message: $message,
                type: $type,
            );
        }

        return $this->appendDirect(
            author: $author,
            to: $to,
            subject: $subject,
            message: $message,
            type: $type,
        );
    }

    public function syncFeed(): array
    {
        return array_map(static fn (array $record) => [
            'id' => (string) ($record['id'] ?? ''),
            'entry' => (string) ($record['entry'] ?? ''),
            'created_at' => (string) ($record['created_at'] ?? ''),
        ], $this->readOutbox());
    }

    public function writeEnabled(): bool
    {
        if ($this->directWriteEnabled()) {
            return true;
        }

        $directory = dirname($this->outboxPath());

        return is_dir($directory) && is_writable($directory);
    }

    public function directWriteEnabled(): bool
    {
        return $this->token !== '';
    }

    private function appendDirect(string $author, string $to, string $subject, string $message, string $type): array
    {
        $entry = $this->formatEntry(
            author: $author,
            to: $to,
            subject: $subject,
            message: $message,
            type: $type,
        );

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
                $last = end($parsed) ?: null;

                if (is_array($last)) {
                    $last['sync_status'] = 'synced';
                }

                return [
                    'message' => $last,
                    'commit_sha' => data_get($payload, 'commit.sha'),
                    'write_enabled' => true,
                    'direct_write_enabled' => true,
                    'delivery_mode' => 'direct',
                    'synced_at' => now()->toIso8601String(),
                ];
            }

            if (! in_array($response->status(), [409, 422], true)) {
                $response->throw();
            }

            usleep(150000 * $attempt);
        }

        throw new RuntimeException('O Agent Chat foi alterado por outro agente durante o envio. Tente novamente.');
    }

    private function queueForGithubActions(string $author, string $to, string $subject, string $message, string $type): array
    {
        $id = (string) Str::uuid();
        $entry = rtrim($this->formatEntry(
            author: $author,
            to: $to,
            subject: $subject,
            message: $message,
            type: $type,
        )) . "\n" . $this->marker($id) . "\n";

        $record = [
            'id' => $id,
            'entry' => $entry,
            'created_at' => now()->toIso8601String(),
        ];

        $this->mutateOutbox(function (array $records) use ($record) {
            $records[] = $record;

            return $records;
        });

        $parsed = $this->parseMessages($entry);
        $structured = $parsed[0] ?? [
            'id' => 'pending-' . $id,
            'timestamp' => now('America/Sao_Paulo')->format('Y-m-d H:i') . ' BRT',
            'author' => $author,
            'type' => $type,
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'repo' => $this->repository,
            'branch' => $this->branch,
            'commit' => 'n/a',
            'status' => $type,
        ];
        $structured['id'] = 'pending-' . $id;
        $structured['sync_status'] = 'pending';

        return [
            'message' => $structured,
            'commit_sha' => null,
            'write_enabled' => true,
            'direct_write_enabled' => false,
            'delivery_mode' => 'github_actions_sync',
            'synced_at' => now()->toIso8601String(),
        ];
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
        if ($requireToken && ! $this->directWriteEnabled()) {
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

        if ($this->directWriteEnabled()) {
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
                'sync_status' => 'synced',
            ];
        }

        return $messages;
    }

    private function pendingRecords(string $githubContent): array
    {
        return $this->mutateOutbox(function (array $records) use ($githubContent) {
            return array_values(array_filter($records, function (array $record) use ($githubContent) {
                $id = (string) ($record['id'] ?? '');

                return $id !== '' && ! str_contains($githubContent, $this->marker($id));
            }));
        });
    }

    private function readOutbox(): array
    {
        $path = $this->outboxPath();

        if (! is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('Não foi possível ler a fila local do Agent Chat.');
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                throw new RuntimeException('Não foi possível bloquear a fila local do Agent Chat para leitura.');
            }

            $raw = stream_get_contents($handle);
            $records = json_decode($raw ?: '[]', true);

            return is_array($records) ? array_values($records) : [];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function mutateOutbox(callable $callback): array
    {
        $path = $this->outboxPath();
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório da fila local do Agent Chat.');
        }

        $handle = @fopen($path, 'c+');
        if (! $handle) {
            throw new RuntimeException('Não foi possível abrir a fila local do Agent Chat.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Não foi possível bloquear a fila local do Agent Chat.');
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $records = json_decode($raw ?: '[]', true);
            $records = is_array($records) ? array_values($records) : [];

            $next = $callback($records);
            if (! is_array($next)) {
                throw new RuntimeException('A atualização da fila local do Agent Chat retornou dados inválidos.');
            }

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode(array_values($next), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            fflush($handle);

            return array_values($next);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function outboxPath(): string
    {
        return storage_path('app/agent-chat-outbox.json');
    }

    private function marker(string $id): string
    {
        return sprintf('<!-- agent-chat-id:%s -->', $id);
    }

    private function singleLine(string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    }
}
