<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseExportService
{
    /**
     * @return array{path:string,filename:string,size_bytes:int,sha256:string,driver:string,connection:string}
     */
    public function create(): array
    {
        $connectionName = (string) config('database.default');
        $connection = (array) config("database.connections.{$connectionName}", []);
        $driver = strtolower((string) ($connection['driver'] ?? ''));

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Manual SQL export is supported only for MySQL/MariaDB; current driver: {$driver}.");
        }

        $database = trim((string) ($connection['database'] ?? ''));
        $username = trim((string) ($connection['username'] ?? ''));

        if ($database === '' || $username === '') {
            throw new RuntimeException('Database export aborted because database credentials are incomplete.');
        }

        $directory = storage_path('app/private/admin-database-exports');
        File::ensureDirectoryExists($directory, 0700, true);

        $stamp = now()->format('Ymd-His');
        $filename = "petertecnet-database-{$stamp}.sql.gz";
        $path = $directory.DIRECTORY_SEPARATOR.'.'.uniqid('export-', true).'.sql.gz';
        $gzip = gzopen($path, 'wb9');

        if ($gzip === false) {
            throw new RuntimeException('Unable to create the temporary database export.');
        }

        @chmod($path, 0600);
        $stderr = '';
        $uncompressedBytes = 0;
        $closed = false;

        try {
            $process = new Process(
                $this->dumpCommand($connection),
                base_path(),
                ['MYSQL_PWD' => (string) ($connection['password'] ?? '')],
                null,
                900
            );

            $process->run(function (string $type, string $buffer) use ($gzip, &$stderr, &$uncompressedBytes): void {
                if ($type === Process::OUT) {
                    $written = gzwrite($gzip, $buffer);
                    if ($written === false) {
                        throw new RuntimeException('Unable to write the compressed database export.');
                    }

                    $uncompressedBytes += $written;
                    return;
                }

                if (strlen($stderr) < 8000) {
                    $stderr .= $buffer;
                }
            });

            gzclose($gzip);
            $closed = true;

            if (! $process->isSuccessful()) {
                throw new RuntimeException($this->safeProcessFailure($stderr));
            }

            if ($uncompressedBytes < 1 || ! File::exists($path) || File::size($path) < 1) {
                throw new RuntimeException('mysqldump produced an empty database export.');
            }

            $verification = new Process(['gzip', '-t', $path], base_path(), null, null, 120);
            $verification->run();
            if (! $verification->isSuccessful()) {
                throw new RuntimeException('Database export failed gzip integrity verification.');
            }

            return [
                'path' => $path,
                'filename' => $filename,
                'size_bytes' => File::size($path),
                'sha256' => hash_file('sha256', $path) ?: '',
                'driver' => $driver,
                'connection' => $connectionName,
            ];
        } catch (Throwable $exception) {
            if (! $closed && is_resource($gzip)) {
                gzclose($gzip);
            }
            File::delete($path);
            throw $exception;
        }
    }

    public function delete(string $path): void
    {
        if ($path !== '') {
            File::delete($path);
        }
    }

    private function dumpCommand(array $connection): array
    {
        $command = [
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--triggers',
            '--hex-blob',
            '--skip-comments',
            '--default-character-set='.(string) ($connection['charset'] ?? 'utf8mb4'),
            '--user='.(string) $connection['username'],
        ];

        if ($this->supportsNoTablespaces()) {
            $command[] = '--no-tablespaces';
        }

        $socket = trim((string) ($connection['unix_socket'] ?? ''));
        if ($socket !== '') {
            $command[] = '--socket='.$socket;
        } else {
            $command[] = '--host='.(string) ($connection['host'] ?? '127.0.0.1');
            $command[] = '--port='.(string) ($connection['port'] ?? 3306);
        }

        $command[] = (string) $connection['database'];

        return $command;
    }

    private function supportsNoTablespaces(): bool
    {
        try {
            $process = new Process(['mysqldump', '--help'], base_path(), null, null, 10);
            $process->run();

            return $process->isSuccessful() && str_contains($process->getOutput(), '--no-tablespaces');
        } catch (Throwable) {
            return false;
        }
    }

    private function safeProcessFailure(string $stderr): string
    {
        $message = trim($stderr);
        if ($message === '') {
            return 'mysqldump exited with a non-zero status.';
        }

        $message = preg_replace('/(?:password|passwd|pwd)\s*[=:]\s*\S+/i', 'credential=[redacted]', $message) ?? $message;

        return mb_substr($message, 0, 1000);
    }
}
