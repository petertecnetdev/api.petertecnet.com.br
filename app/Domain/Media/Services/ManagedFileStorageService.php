<?php

namespace App\Domain\Media\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class ManagedFileStorageService
{
    public function fingerprint(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();
        abort_unless($realPath && is_file($realPath), 422, 'Arquivo inválido.');

        return hash_file('sha256', $realPath);
    }

    public function store(UploadedFile $file, string $directory, string $disk = 'local'): array
    {
        $uuid = (string) Str::uuid();
        $extension = strtolower((string) ($file->extension() ?: $file->getClientOriginalExtension() ?: 'bin'));
        $safeExtension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        $filename = $uuid.'.'.$safeExtension;
        $storedPath = $file->storeAs(trim($directory, '/'), $filename, $disk);

        if (! $storedPath) {
            throw new RuntimeException('Não foi possível armazenar o arquivo.');
        }

        return [
            'uuid' => $uuid,
            'original_name' => Str::limit(basename((string) $file->getClientOriginalName()), 255, ''),
            'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'extension' => $safeExtension,
            'file_size' => (int) ($file->getSize() ?: 0),
            'sha256' => $this->fingerprint($file),
            'storage_disk' => $disk,
            'storage_path' => $storedPath,
        ];
    }

    public function delete(string $disk, string $path): bool
    {
        return Storage::disk($disk)->delete($path);
    }

    public function exists(string $disk, string $path): bool
    {
        return Storage::disk($disk)->exists($path);
    }

    public function disk(string $disk)
    {
        return Storage::disk($disk);
    }
}
