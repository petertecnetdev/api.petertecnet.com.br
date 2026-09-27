<?php

namespace App\Domain\Media\Library\Services;

use App\Domain\Media\Library\Models\MediaAsset;
use App\Domain\Media\Library\Models\MediaCollection;
use App\Domain\Media\Library\Models\MediaCollectionItem;
use App\Domain\Media\Library\Models\MediaRelation;
use App\Domain\Media\Services\ManagedFileStorageService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class MediaLibraryService
{
    public function __construct(
        private readonly ManagedFileStorageService $storage,
        private readonly MediaVariantService $variants,
    ) {
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = MediaAsset::query()->with(['application:id,name,slug', 'variants', 'relations']);

        if (! empty($filters['application_id'])) {
            $query->where('application_id', (int) $filters['application_id']);
        }

        foreach (['kind', 'category', 'purpose', 'visibility', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        foreach (['is_official', 'is_marketing_approved', 'is_ai_generated'] as $field) {
            if (array_key_exists($field, $filters) && $filters[$field] !== null) {
                $query->where($field, (bool) $filters[$field]);
            }
        }

        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            $query->where(function ($asset) use ($term): void {
                $asset
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('title', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhere('alt_text', 'like', "%{$term}%")
                    ->orWhere('category', 'like', "%{$term}%")
                    ->orWhere('purpose', 'like', "%{$term}%");
            });
        }

        $paginator = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 30));

        $paginator->through(fn (MediaAsset $asset): array => $this->serialize($asset));

        return $paginator;
    }

    public function createUpload(int $applicationId, ?int $actorUserId, UploadedFile $file, array $attributes): MediaAsset
    {
        $mimeType = strtolower((string) ($file->getMimeType() ?: 'application/octet-stream'));
        $this->assertAllowedMime($mimeType);

        $kind = $this->kindFromMime($mimeType);
        $this->assertUploadBudget($file, $kind);
        [$width, $height] = $kind === 'image' ? $this->imageDimensions($file) : [null, null];
        $this->assertImageDimensions($kind, $width, $height);

        $category = $this->taxonomy($attributes['category'] ?? 'general');
        $purpose = $this->taxonomy($attributes['purpose'] ?? 'general');
        $disk = (string) config('media_library.disk', 'public');

        $stored = $this->storage->storeForApplication(
            $file,
            $applicationId,
            'library/'.$category,
            $disk
        );

        $asset = MediaAsset::query()->create([
            'uuid' => $stored['uuid'],
            'application_id' => $applicationId,
            'owner_user_id' => $attributes['owner_user_id'] ?? null,
            'name' => trim((string) ($attributes['name'] ?? '')) ?: $stored['original_name'],
            'title' => $this->nullableString($attributes['title'] ?? null),
            'description' => $this->nullableString($attributes['description'] ?? null),
            'alt_text' => $this->nullableString($attributes['alt_text'] ?? null),
            'kind' => $kind,
            'category' => $category,
            'purpose' => $purpose,
            'mime_type' => $mimeType,
            'extension' => $stored['extension'],
            'file_size' => $stored['file_size'],
            'width' => $width,
            'height' => $height,
            'storage_disk' => $stored['storage_disk'],
            'storage_path' => $stored['storage_path'],
            'public_url' => $this->storageUrl($stored['storage_disk'], $stored['storage_path']),
            'checksum' => $stored['sha256'],
            'visibility' => $attributes['visibility'] ?? 'private',
            'status' => 'ready',
            'is_official' => (bool) ($attributes['is_official'] ?? false),
            'is_marketing_approved' => (bool) ($attributes['is_marketing_approved'] ?? false),
            'is_ai_generated' => (bool) ($attributes['is_ai_generated'] ?? false),
            'metadata' => $attributes['metadata'] ?? null,
            'created_by' => $actorUserId,
            'approved_by' => ! empty($attributes['is_marketing_approved']) ? $actorUserId : null,
            'approved_at' => ! empty($attributes['is_marketing_approved']) ? now() : null,
        ]);

        if ($asset->kind === 'image') {
            $this->variants->generate($asset);
        }

        return $asset->fresh(['application:id,name,slug', 'variants', 'relations']);
    }

    public function createExternal(int $applicationId, ?int $actorUserId, string $url, array $attributes): MediaAsset
    {
        if (! Str::startsWith(strtolower($url), 'https://')) {
            throw new InvalidArgumentException('External media URL must use HTTPS.');
        }

        $kind = $attributes['kind'] ?? 'image';
        if (! in_array($kind, ['image', 'video', 'document'], true)) {
            throw new InvalidArgumentException('Unsupported media kind.');
        }

        $pathName = basename((string) parse_url($url, PHP_URL_PATH));
        $asset = MediaAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_id' => $applicationId,
            'owner_user_id' => $attributes['owner_user_id'] ?? null,
            'name' => trim((string) ($attributes['name'] ?? '')) ?: ($pathName ?: 'Mídia externa'),
            'title' => $this->nullableString($attributes['title'] ?? null),
            'description' => $this->nullableString($attributes['description'] ?? null),
            'alt_text' => $this->nullableString($attributes['alt_text'] ?? null),
            'kind' => $kind,
            'category' => $this->taxonomy($attributes['category'] ?? 'general'),
            'purpose' => $this->taxonomy($attributes['purpose'] ?? 'general'),
            'mime_type' => $this->nullableString($attributes['mime_type'] ?? null),
            'public_url' => $url,
            'visibility' => $attributes['visibility'] ?? 'private',
            'status' => 'ready',
            'is_official' => (bool) ($attributes['is_official'] ?? false),
            'is_marketing_approved' => (bool) ($attributes['is_marketing_approved'] ?? false),
            'is_ai_generated' => (bool) ($attributes['is_ai_generated'] ?? false),
            'metadata' => array_merge((array) ($attributes['metadata'] ?? []), ['source' => 'external_url']),
            'created_by' => $actorUserId,
            'approved_by' => ! empty($attributes['is_marketing_approved']) ? $actorUserId : null,
            'approved_at' => ! empty($attributes['is_marketing_approved']) ? now() : null,
        ]);

        return $asset->fresh(['application:id,name,slug', 'variants', 'relations']);
    }

    public function update(MediaAsset $asset, ?int $actorUserId, array $data): MediaAsset
    {
        foreach (['name', 'title', 'description', 'alt_text'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $field === 'name'
                    ? trim((string) $data[$field])
                    : $this->nullableString($data[$field]);
            }
        }

        foreach (['category', 'purpose'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->taxonomy($data[$field]);
            }
        }

        if (array_key_exists('is_marketing_approved', $data)) {
            if ((bool) $data['is_marketing_approved']) {
                $data['approved_by'] = $actorUserId;
                $data['approved_at'] = now();
            } else {
                $data['approved_by'] = null;
                $data['approved_at'] = null;
            }
        }

        $asset->fill($data);
        $asset->save();

        return $asset->fresh(['application:id,name,slug', 'variants', 'relations']);
    }

    public function archive(MediaAsset $asset): void
    {
        $asset->update(['status' => 'archived']);
        $asset->delete();
    }

    public function addRelation(MediaAsset $asset, array $data): MediaRelation
    {
        return MediaRelation::query()->updateOrCreate(
            [
                'media_asset_id' => $asset->id,
                'entity_type' => $data['entity_type'],
                'entity_id' => (string) $data['entity_id'],
                'role' => $data['role'] ?? 'media',
            ],
            [
                'application_id' => $asset->application_id,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'metadata' => $data['metadata'] ?? null,
            ]
        );
    }

    public function collectionList(?int $applicationId = null): array
    {
        return MediaCollection::query()
            ->withCount('assets')
            ->when($applicationId, fn ($query) => $query->where('application_id', $applicationId))
            ->orderBy('name')
            ->get()
            ->map(fn (MediaCollection $collection): array => [
                'id' => $collection->id,
                'application_id' => $collection->application_id,
                'name' => $collection->name,
                'slug' => $collection->slug,
                'description' => $collection->description,
                'purpose' => $collection->purpose,
                'visibility' => $collection->visibility,
                'status' => $collection->status,
                'assets_count' => $collection->assets_count,
                'metadata' => $collection->metadata,
                'updated_at' => $collection->updated_at,
            ])->all();
    }

    public function createCollection(int $applicationId, ?int $actorUserId, array $data): MediaCollection
    {
        $base = Str::slug((string) $data['name']) ?: 'collection';
        $slug = $base;
        $counter = 2;

        while (MediaCollection::withTrashed()->where('application_id', $applicationId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return MediaCollection::query()->create([
            'application_id' => $applicationId,
            'owner_user_id' => $data['owner_user_id'] ?? $actorUserId,
            'name' => trim((string) $data['name']),
            'slug' => $slug,
            'description' => $this->nullableString($data['description'] ?? null),
            'purpose' => $this->taxonomy($data['purpose'] ?? 'general'),
            'visibility' => $data['visibility'] ?? 'private',
            'status' => $data['status'] ?? 'active',
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    public function attachToCollection(MediaCollection $collection, MediaAsset $asset, array $data = []): void
    {
        abort_unless(
            (int) $collection->application_id === (int) $asset->application_id,
            422,
            'A coleção e a mídia precisam pertencer à mesma aplicação.'
        );

        MediaCollectionItem::query()->updateOrCreate(
            [
                'media_collection_id' => $collection->id,
                'media_asset_id' => $asset->id,
            ],
            [
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'metadata' => $data['metadata'] ?? null,
            ]
        );
    }

    public function marketing(int $applicationId, array $filters): array
    {
        $limit = max(1, min(60, (int) ($filters['limit'] ?? 24)));

        return MediaAsset::query()
            ->with(['application:id,name,slug', 'variants'])
            ->where('application_id', $applicationId)
            ->where('status', 'ready')
            ->where('visibility', 'public')
            ->where('is_marketing_approved', true)
            ->when(! empty($filters['kind']), fn ($query) => $query->where('kind', $filters['kind']))
            ->when(! empty($filters['category']), fn ($query) => $query->where('category', $filters['category']))
            ->when(! empty($filters['purpose']), fn ($query) => $query->where('purpose', $filters['purpose']))
            ->when(
                array_key_exists('is_official', $filters) && $filters['is_official'] !== null,
                fn ($query) => $query->where('is_official', (bool) $filters['is_official'])
            )
            ->orderByDesc('is_official')
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (MediaAsset $asset): array => $this->serialize($asset, false))
            ->all();
    }

    public function serialize(MediaAsset $asset, bool $includeInternal = true): array
    {
        $asset->loadMissing(['application:id,name,slug', 'variants', 'relations']);

        $payload = [
            'id' => $asset->id,
            'uuid' => $asset->uuid,
            'application_id' => $asset->application_id,
            'application' => $asset->application ? [
                'id' => $asset->application->id,
                'name' => $asset->application->name,
                'slug' => $asset->application->slug,
            ] : null,
            'name' => $asset->name,
            'title' => $asset->title,
            'description' => $asset->description,
            'alt_text' => $asset->alt_text,
            'kind' => $asset->kind,
            'category' => $asset->category,
            'purpose' => $asset->purpose,
            'mime_type' => $asset->mime_type,
            'file_size' => $asset->file_size,
            'width' => $asset->width,
            'height' => $asset->height,
            'duration_ms' => $asset->duration_ms,
            'url' => $asset->public_url,
            'visibility' => $asset->visibility,
            'status' => $asset->status,
            'is_official' => $asset->is_official,
            'is_marketing_approved' => $asset->is_marketing_approved,
            'is_ai_generated' => $asset->is_ai_generated,
            'approved_at' => $asset->approved_at,
            'created_at' => $asset->created_at,
            'updated_at' => $asset->updated_at,
            'variants' => $asset->variants->mapWithKeys(fn ($variant) => [
                $variant->name => [
                    'url' => $variant->public_url,
                    'mime_type' => $variant->mime_type,
                    'file_size' => $variant->file_size,
                    'width' => $variant->width,
                    'height' => $variant->height,
                    ...($includeInternal ? ['metadata' => $variant->metadata] : []),
                ],
            ])->all(),
        ];

        if ($includeInternal) {
            $payload['metadata'] = $asset->metadata;
            $payload['storage'] = [
                'disk' => $asset->storage_disk,
                'path' => $asset->storage_path,
                'checksum' => $asset->checksum,
            ];
            $payload['relations'] = $asset->relations->map(fn ($relation) => [
                'id' => $relation->id,
                'entity_type' => $relation->entity_type,
                'entity_id' => $relation->entity_id,
                'role' => $relation->role,
                'sort_order' => $relation->sort_order,
                'metadata' => $relation->metadata,
            ])->values()->all();
        }

        return $payload;
    }

    private function assertUploadBudget(UploadedFile $file, string $kind): void
    {
        $sizeKb = (int) ceil(max(0, (int) $file->getSize()) / 1024);
        $limit = match ($kind) {
            'image' => (int) config('media_library.max_image_upload_kb', 20480),
            'video' => (int) config('media_library.max_video_upload_kb', 153600),
            default => (int) config('media_library.max_document_upload_kb', 30720),
        };

        if ($sizeKb > max(1, $limit)) {
            throw new InvalidArgumentException('Media file exceeds the allowed size for its type.');
        }
    }

    private function assertImageDimensions(string $kind, ?int $width, ?int $height): void
    {
        if ($kind !== 'image') {
            return;
        }

        if (! $width || ! $height) {
            throw new InvalidArgumentException('Image dimensions could not be validated.');
        }

        $pixels = $width * $height;
        if ($pixels > max(1000000, (int) config('media_library.max_image_pixels', 40000000))) {
            throw new InvalidArgumentException('Image dimensions exceed the safe processing budget.');
        }
    }

    private function assertAllowedMime(string $mimeType): void
    {
        if (! in_array($mimeType, (array) config('media_library.allowed_mime_types', []), true)) {
            throw new InvalidArgumentException('Unsupported media type.');
        }
    }

    private function kindFromMime(string $mimeType): string
    {
        if (Str::startsWith($mimeType, 'image/')) return 'image';
        if (Str::startsWith($mimeType, 'video/')) return 'video';
        return 'document';
    }

    private function imageDimensions(UploadedFile $file): array
    {
        try {
            $dimensions = getimagesize($file->getRealPath());

            return [
                isset($dimensions[0]) ? (int) $dimensions[0] : null,
                isset($dimensions[1]) ? (int) $dimensions[1] : null,
            ];
        } catch (Throwable) {
            return [null, null];
        }
    }

    private function storageUrl(string $diskName, string $path): ?string
    {
        try {
            $url = Storage::disk($diskName)->url($path);
            return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
        } catch (Throwable) {
            return null;
        }
    }

    private function taxonomy(mixed $value): string
    {
        $normalized = Str::of((string) $value)
            ->lower()
            ->replaceMatches('/[^a-z0-9_-]+/', '-')
            ->trim('-')
            ->limit(80, '')
            ->toString();

        return $normalized !== '' ? $normalized : 'general';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
