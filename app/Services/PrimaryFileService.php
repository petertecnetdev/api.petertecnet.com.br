<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PrimaryFileService
{
    public function replace(
        UploadedFile $uploaded,
        int $appId,
        string $entityName,
        int $entityId,
        ?string $group,
        string $visibility,
        int $actorId,
    ): File {
        $extension = strtolower((string) $uploaded->getClientOriginalExtension());
        $uuid = (string) Str::uuid();
        $path = $uploaded->storeAs(
            'uploads/files/' . $entityName . '/' . $entityId,
            $uuid . ($extension ? '.' . $extension : ''),
            'public'
        );

        try {
            return DB::transaction(function () use ($uploaded, $appId, $entityName, $entityId, $group, $visibility, $actorId, $extension, $uuid, $path) {
                $siblings = File::query()
                    ->where('entity_name', $entityName)
                    ->where('entity_id', $entityId)
                    ->when($group === null, fn ($q) => $q->whereNull('group'), fn ($q) => $q->where('group', $group))
                    ->lockForUpdate()
                    ->get();

                $nextVersion = max(1, ((int) $siblings->max('version')) + 1);
                $absolutePath = Storage::disk('public')->path($path);

                File::query()
                    ->whereIn('id', $siblings->where('is_primary', true)->pluck('id'))
                    ->update([
                        'is_primary' => false,
                        'status' => 'inactive',
                        'updated_by' => $actorId,
                        'updated_at' => now(),
                    ]);

                return File::create([
                    'uuid' => $uuid,
                    'app_id' => $appId,
                    'entity_id' => $entityId,
                    'entity_name' => $entityName,
                    'fileable_id' => $entityId,
                    'fileable_type' => $entityName,
                    'original_name' => basename((string) $uploaded->getClientOriginalName()),
                    'extension' => $extension ?: null,
                    'mime_type' => $uploaded->getMimeType() ?: 'application/octet-stream',
                    'file_size' => $uploaded->getSize() ?: 0,
                    'content_hash' => is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null,
                    'type' => str_starts_with((string) $uploaded->getMimeType(), 'image/') ? 'image' : 'file',
                    'storage' => 'public',
                    'path' => $path,
                    'storage_path' => $absolutePath,
                    'public_url' => Storage::disk('public')->url($path),
                    'group' => $group,
                    'position' => 0,
                    'is_primary' => true,
                    'visibility' => $visibility,
                    'visibility_scope' => 'global',
                    'status' => 'active',
                    'source' => 'primary_replace',
                    'version' => $nextVersion,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                    'meta' => [
                        'replacement' => true,
                        'previous_file_ids' => $siblings->pluck('id')->values()->all(),
                    ],
                ]);
            });
        } catch (Throwable $error) {
            Storage::disk('public')->delete($path);
            throw $error;
        }
    }
}
