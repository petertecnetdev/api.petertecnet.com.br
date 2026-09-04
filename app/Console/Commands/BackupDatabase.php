<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalTelemetryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'operations:backup-database {--retention-days=14 : Days to retain successful database backups}';

    protected $description = 'Create, compress and verify a database backup for ecosystem disaster recovery.';

    public function handle(OperationalTelemetryService $telemetry): int
    {
        $connectionName = (string) config('database.default');
        $connection = (array) config("database.connections.{$connectionName}", []);
        $driver = (string) ($connection['driver'] ?? '');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->error("Database backup is supported only for MySQL/MariaDB connections; current driver: {$driver}.");

            return self::FAILURE;
        }

        $database = trim((string) ($connection['database'] ?? ''));
        $username = trim((string) ($connection['username'] ?? ''));

        if ($database === '' || $username === '') {
            $this->error('Database backup aborted because database credentials are incomplete.');

            return self::FAILURE;
        }

        $backupDirectory = storage_path('app/backups');
        File::ensureDirectoryExists($backupDirectory, 0700, true);

        $filename = 'petertecnet-'.now()->format('Ymd-His').'.sql.gz';
        $path = $backupDirectory.DIRECTORY_SEPARATOR.$filename;
        $gzip = gzopen($path, 'wb9');

        if ($gzip === false) {
            $this->error('Unable to open the backup destination for writing.');

            return self::FAILURE;
        }

        $stderr = '';
        $uncompressedBytes = 0;

        try {
            $process = new SymfonyProcess(
                $this->dumpCommand($connection),
                base_path(),
                ['MYSQL_PWD' => (string) ($connection['password'] ?? '')],
                null,
                600
            );

            $process->run(function (string $type, string $buffer) use ($gzip, &$stderr, &$uncompressedBytes): void {
                if ($type === SymfonyProcess::OUT) {
                    $written = gzwrite($gzip, $buffer);
                    if ($written === false) {
                        throw new RuntimeException('Unable to write the compressed database backup.');
                    }
                    $uncompressedBytes += $written;

                    return;
                }

                if (strlen($stderr) < 8000) {
                    $stderr .= $buffer;
                }
            });

            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($stderr) ?: 'mysqldump exited with a non-zero status.');
            }

            if ($uncompressedBytes < 1) {
                throw new RuntimeException('mysqldump produced an empty backup.');
            }
        } catch (Throwable $exception) {
            gzclose($gzip);
            File::delete($path);

            Log::error('Automated database backup failed.', [
                'connection' => $connectionName,
                'exception' => $exception->getMessage(),
            ]);

            $this->error('Database backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        gzclose($gzip);
        @chmod($path, 0600);

        if (! $this->verifyArchive($path)) {
            File::delete($path);
            Log::error('Automated database backup failed gzip integrity verification.', ['file' => $filename]);
            $this->error('Database backup failed gzip integrity verification.');

            return self::FAILURE;
        }

        $size = File::size($path);
        $sha256 = hash_file('sha256', $path) ?: null;
        $removed = $this->pruneOldBackups($backupDirectory, max(1, (int) $this->option('retention-days')));
        $status = $telemetry->inspectBackup();

        Log::info('Automated database backup completed.', [
            'file' => $filename,
            'size_bytes' => $size,
            'sha256' => $sha256,
            'retention_removed' => $removed,
            'mission_control_status' => $status['status'] ?? 'unknown',
        ]);

        $this->info("Database backup created and verified: {$filename} ({$size} bytes).");
        if ($removed > 0) {
            $this->line("Retention removed {$removed} expired backup(s).");
        }

        return self::SUCCESS;
    }

    private function dumpCommand(array $connection): array
    {
        $command = [
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--default-character-set='.(string) ($connection['charset'] ?? 'utf8mb4'),
            '--user='.(string) $connection['username'],
        ];

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

    private function verifyArchive(string $path): bool
    {
        $process = new SymfonyProcess(['gzip', '-t', $path], base_path(), null, null, 120);
        $process->run();

        return $process->isSuccessful() && File::exists($path) && File::size($path) > 0;
    }

    private function pruneOldBackups(string $directory, int $retentionDays): int
    {
        $threshold = now()->subDays($retentionDays)->timestamp;
        $removed = 0;

        foreach (glob($directory.DIRECTORY_SEPARATOR.'petertecnet-*.sql.gz') ?: [] as $candidate) {
            if ($candidate === '' || ! is_file($candidate)) {
                continue;
            }

            $modifiedAt = filemtime($candidate);
            if ($modifiedAt !== false && $modifiedAt < $threshold && File::delete($candidate)) {
                $removed++;
            }
        }

        return $removed;
    }
}
