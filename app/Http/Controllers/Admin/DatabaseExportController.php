<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcosystemAuditLog;
use App\Services\Admin\DatabaseExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class DatabaseExportController extends Controller
{
    public function __invoke(Request $request, DatabaseExportService $exports): BinaryFileResponse
    {
        $actor = $request->user('api') ?? $request->user();
        $export = null;

        try {
            $export = $exports->create();

            EcosystemAuditLog::query()->create([
                'user_id' => $actor?->id,
                'action' => 'database_export_download',
                'entity_type' => 'database',
                'entity_id' => null,
                'before' => null,
                'after' => [
                    'status' => 'ready',
                    'driver' => $export['driver'],
                    'connection' => $export['connection'],
                    'filename' => $export['filename'],
                    'size_bytes' => $export['size_bytes'],
                    'sha256' => $export['sha256'],
                ],
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            ]);

            Log::notice('Privileged database export prepared.', [
                'actor_id' => $actor?->id,
                'driver' => $export['driver'],
                'connection' => $export['connection'],
                'size_bytes' => $export['size_bytes'],
                'sha256' => $export['sha256'],
            ]);

            return response()
                ->download($export['path'], $export['filename'], [
                    'Content-Type' => 'application/gzip',
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                    'X-Content-Type-Options' => 'nosniff',
                ])
                ->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            if (is_array($export) && ! empty($export['path'])) {
                $exports->delete((string) $export['path']);
            }

            Log::error('Privileged database export failed.', [
                'actor_id' => $actor?->id,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
