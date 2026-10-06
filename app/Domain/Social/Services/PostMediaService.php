<?php

namespace App\Domain\Social\Services;

use App\Domain\Media\Services\ManagedFileStorageService;
use App\Domain\Media\Services\MediaContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PostMediaService
{
    public const MAX_ITEMS = 10;
    public const MAX_IMAGE_BYTES = 12 * 1024 * 1024;
    public const MAX_VIDEO_BYTES = 60 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 120 * 1024 * 1024;

    private const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const VIDEO_MIME_TYPES = [
        'video/mp4',
        'video/quicktime',
        'video/webm',
    ];

    public function __construct(private readonly ManagedFileStorageService $storage)
    {
    }

    /**
     * @return array<int, UploadedFile>
     */
    public function normalizeUploads(mixed $files): array
    {
        return collect(is_array($files) ? $files : ($files ? [$files] : []))
            ->filter(fn ($file) => $file instanceof UploadedFile && $file->isValid())
            ->values()
            ->all();
    }

    /**
     * @param array<int, UploadedFile> $files
     */
    public function assertUploads(array $files): void
    {
        abort_if(count($files) > self::MAX_ITEMS, 422, 'Você pode publicar até '.self::MAX_ITEMS.' fotos ou vídeos por vez.');

        $totalBytes = 0;
        foreach ($files as $file) {
            $mime = strtolower((string) $file->getMimeType());
            $size = (int) ($file->getSize() ?: 0);
            $isImage = in_array($mime, self::IMAGE_MIME_TYPES, true);
            $isVideo = in_array($mime, self::VIDEO_MIME_TYPES, true);

            abort_unless($isImage || $isVideo, 422, 'Formato de mídia não suportado. Use JPG, PNG, WEBP, MP4, MOV ou WEBM.');
            abort_if($isImage && $size > self::MAX_IMAGE_BYTES, 422, 'Cada imagem pode ter no máximo 12 MB.');
            abort_if($isVideo && $size > self::MAX_VIDEO_BYTES, 422, 'Cada vídeo pode ter no máximo 60 MB.');

            $totalBytes += $size;
        }

        abort_if($totalBytes > self::MAX_TOTAL_BYTES, 422, 'A publicação pode ter no máximo 120 MB de mídia no total.');
    }

    /**
     * @param array<int, UploadedFile> $files
     * @return array<int, array<string, mixed>>
     */
    public function storeForPost(int $appId, int $postId, int $userId, array $files): array
    {
        if ($files === []) {
            return [];
        }

        $this->assertUploads($files);
        $storedFiles = [];
        $media = [];

        try {
            foreach ($files as $position => $file) {
                $stored = $this->storage->storeForApplication(
                    $file,
                    $appId,
                    MediaContext::POST_MEDIA.'/posts/'.$postId,
                    'public'
                );
                $storedFiles[] = [$stored['storage_disk'], $stored['storage_path']];

                $mime = strtolower((string) $stored['mime_type']);
                $type = str_starts_with($mime, 'video/') ? 'video' : 'image';

                $id = DB::table('post_media')->insertGetId([
                    'app_id' => $appId,
                    'post_id' => $postId,
                    'user_id' => $userId,
                    'type' => $type,
                    'mime_type' => $mime,
                    'storage_disk' => $stored['storage_disk'],
                    'storage_path' => $stored['storage_path'],
                    'original_name' => $stored['original_name'],
                    'file_size' => $stored['file_size'],
                    'position' => $position,
                    'alt_text' => null,
                    'status' => 'published',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $media[] = $this->payload((object) [
                    'id' => $id,
                    'post_id' => $postId,
                    'type' => $type,
                    'mime_type' => $mime,
                    'storage_disk' => $stored['storage_disk'],
                    'storage_path' => $stored['storage_path'],
                    'original_name' => $stored['original_name'],
                    'file_size' => $stored['file_size'],
                    'position' => $position,
                    'alt_text' => null,
                ]);
            }
        } catch (Throwable $e) {
            foreach ($storedFiles as [$disk, $path]) {
                try {
                    $this->storage->delete($disk, $path);
                } catch (Throwable) {
                    // Database rollback remains authoritative; stale files can be cleaned by storage maintenance.
                }
            }
            throw $e;
        }

        return $media;
    }

    /**
     * @param iterable<int, int|string> $postIds
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    public function forPostIds(int $appId, iterable $postIds): Collection
    {
        $ids = collect($postIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table('post_media')
            ->where('app_id', $appId)
            ->whereIn('post_id', $ids)
            ->where('status', 'published')
            ->orderBy('post_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => $this->payload($row))
            ->groupBy('post_id');
    }

    /**
     * @param array<int, int|string> $postIds
     */
    public function hideForPosts(int $appId, array $postIds): void
    {
        $ids = collect($postIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        DB::table('post_media')
            ->where('app_id', $appId)
            ->whereIn('post_id', $ids)
            ->update(['status' => 'hidden', 'updated_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(object $row): array
    {
        $path = (string) ($row->storage_path ?? '');

        return [
            'id' => (int) $row->id,
            'post_id' => (int) $row->post_id,
            'type' => (string) $row->type,
            'mime_type' => (string) $row->mime_type,
            'url' => $path !== '' ? $path : null,
            'path' => $path,
            'original_name' => $row->original_name ?? null,
            'file_size' => (int) ($row->file_size ?? 0),
            'position' => (int) ($row->position ?? 0),
            'alt_text' => $row->alt_text ?? null,
        ];
    }
}
