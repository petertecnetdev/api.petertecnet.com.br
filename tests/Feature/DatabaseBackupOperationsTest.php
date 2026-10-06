<?php

namespace Tests\Feature;

use App\Services\Operations\OperationalTelemetryService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DatabaseBackupOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_command_refuses_unsupported_database_drivers(): void
    {
        $this->assertSame('sqlite', config('database.default'));

        $this->artisan('operations:backup-database')
            ->expectsOutputToContain('supported only for MySQL/MariaDB')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_fresh_database_backup_is_reported_as_healthy(): void
    {
        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/petertecnet-20990101-000000.sql.gz';
        $previousHeartbeat = DB::table('admin_runtime_heartbeats')->where('service', 'backup')->first();

        File::put($path, gzencode('-- verified backup fixture --'));
        touch($path, time());

        try {
            $status = app(OperationalTelemetryService::class)->inspectBackup();

            $this->assertSame('healthy', $status['status']);
            $this->assertSame(basename($path), $status['meta']['file']);
            $this->assertDatabaseHas('admin_runtime_heartbeats', [
                'service' => 'backup',
                'status' => 'healthy',
            ]);
        } finally {
            File::delete($path);

            if ($previousHeartbeat) {
                DB::table('admin_runtime_heartbeats')->where('service', 'backup')->update((array) $previousHeartbeat);
            } else {
                DB::table('admin_runtime_heartbeats')->where('service', 'backup')->delete();
            }
        }
    }
}
