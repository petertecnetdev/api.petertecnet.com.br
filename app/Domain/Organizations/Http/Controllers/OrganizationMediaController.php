<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employer;
use App\Models\Interaction;
use App\Models\Production;
use App\Services\AppNotificationService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Throwable;

final class OrganizationMediaController extends Controller
{
    private const MAX_MEDIA = 40;
    private const MAX_UPLOAD_KB = 10240;
    private const RESTORE_WINDOW_HOURS = 24;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AppNotificationService $notifications,
    ) {}

    public function store(Request $request, int $organizationId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $data = $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:'.self::MAX_UPLOAD_KB,
            'caption' => 'nullable|string|max:180',
            'alt_text' => 'nullable|string|max:255',
            'album_id' => 'nullable|integer|min:1',
        ]);

        $this->assertAlbum($organization, $data['album_id'] ?? null);
        $appId = $this->context->id();
        $count = $this->activeMediaQuery($organization)->count();
        abort_if($count >= self::MAX_MEDIA, 422, 'A galeria pode ter até '.self::MAX_MEDIA.' fotos.');

        $file = $data['photo'];
        $checksum = hash_file('sha256', $file->getRealPath());
        $duplicate = $this->activeMediaQuery($organization)->where('checksum', $checksum)->first();
        $variants = $this->storeImageVariants($organization, $file);
        $similar = $duplicate ? null : $this->findVisuallySimilar($organization, $variants['perceptual_hash'] ?? null);
        $position = (int) ($this->activeMediaQuery($organization)->max('position') ?? -1) + 1;
        $caption = trim((string) ($data['caption'] ?? '')) ?: null;
        $alt = trim((string) ($data['alt_text'] ?? ''))
            ?: $caption
            ?: $organization->name.' - foto da galeria';

        $id = DB::table('organization_media')->insertGetId([
            'app_id' => $appId,
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'album_id' => $data['album_id'] ?? null,
            'path' => $variants['path'],
            'original_path' => $variants['original_path'],
            'thumbnail_path' => $variants['thumbnail_path'],
            'caption' => $caption,
            'alt_text' => $alt,
            'is_featured' => false,
            'status' => 'published',
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'width' => $variants['width'],
            'height' => $variants['height'],
            'checksum' => $checksum,
            'perceptual_hash' => $variants['perceptual_hash'],
            'brightness_score' => $variants['brightness_score'],
            'sharpness_score' => $variants['sharpness_score'],
            'cover_score' => $variants['cover_score'],
            'processing_version' => 2,
            'last_processed_at' => now(),
            'focal_x' => 50,
            'focal_y' => 50,
            'rotation' => 0,
            'position' => $position,
            'updated_by_user_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->track($organization, $request, 'production_media.upload', [
            'media_id' => $id,
            'duplicate_of' => $duplicate?->id,
            'similar_to' => $similar?->id,
            'width' => $variants['width'],
            'height' => $variants['height'],
            'file_size' => $file->getSize(),
        ]);

        $media = DB::table('organization_media')->where('id', $id)->first();

        return response()->json([
            'message' => 'Foto adicionada à galeria.',
            'media' => $this->mediaPayload($media, true),
            'warning' => $duplicate
                ? 'Esta imagem parece já existir na galeria.'
                : ($similar ? 'Esta foto é visualmente muito parecida com outra imagem já publicada.' : null),
            'duplicate_of' => $duplicate ? (int) $duplicate->id : null,
            'similar_to' => $similar ? (int) $similar->id : null,
            'quality_warnings' => $this->qualityWarnings(
                $variants['width'],
                $variants['height'],
                $variants['brightness_score'],
                $variants['sharpness_score']
            ),
            'limit' => self::MAX_MEDIA,
        ], 201);
    }

    public function update(Request $request, int $organizationId, int $mediaId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $row = $this->mediaRow($organization, $mediaId);
        $data = $request->validate([
            'caption' => 'sometimes|nullable|string|max:180',
            'alt_text' => 'sometimes|nullable|string|max:255',
            'is_featured' => 'sometimes|boolean',
            'album_id' => 'sometimes|nullable|integer|min:1',
            'focal_x' => 'sometimes|integer|min:0|max:100',
            'focal_y' => 'sometimes|integer|min:0|max:100',
        ]);

        if (array_key_exists('album_id', $data)) {
            $this->assertAlbum($organization, $data['album_id']);
        }

        if (! empty($data['is_featured']) && ! (bool) ($row->is_featured ?? false)) {
            abort_if(
                $this->activeMediaQuery($organization)->where('is_featured', true)->count() >= 6,
                422,
                'Você pode destacar até 6 fotos na galeria.'
            );
        }

        foreach (['caption', 'alt_text'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = trim((string) ($data[$field] ?? '')) ?: null;
            }
        }

        $data['updated_by_user_id'] = $request->user()->id;
        $data['updated_at'] = now();
        DB::table('organization_media')->where('id', $row->id)->update($data);

        $this->track($organization, $request, 'production_media.update', [
            'media_id' => $row->id,
            'fields' => array_keys($data),
        ]);

        return response()->json([
            'message' => 'Foto atualizada.',
            'media' => $this->mediaPayload(DB::table('organization_media')->where('id', $row->id)->first(), true),
        ]);
    }

    public function replace(Request $request, int $organizationId, int $mediaId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $row = $this->mediaRow($organization, $mediaId);
        $data = $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:'.self::MAX_UPLOAD_KB,
        ]);

        $file = $data['photo'];
        $variants = $this->storeImageVariants($organization, $file);
        $checksum = hash_file('sha256', $file->getRealPath());

        DB::table('organization_media')->where('id', $row->id)->update([
            'path' => $variants['path'],
            'original_path' => $variants['original_path'],
            'thumbnail_path' => $variants['thumbnail_path'],
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'width' => $variants['width'],
            'height' => $variants['height'],
            'checksum' => $checksum,
            'perceptual_hash' => $variants['perceptual_hash'],
            'brightness_score' => $variants['brightness_score'],
            'sharpness_score' => $variants['sharpness_score'],
            'cover_score' => $variants['cover_score'],
            'processing_version' => 2,
            'last_processed_at' => now(),
            'rotation' => 0,
            'updated_by_user_id' => $request->user()->id,
            'updated_at' => now(),
        ]);

        $this->deleteStoredVariants($row);
        $this->track($organization, $request, 'production_media.replace', ['media_id' => $row->id]);

        return response()->json([
            'message' => 'Foto substituída sem perder sua posição e legenda.',
            'media' => $this->mediaPayload(DB::table('organization_media')->where('id', $row->id)->first(), true),
            'quality_warnings' => $this->qualityWarnings(
                $variants['width'],
                $variants['height'],
                $variants['brightness_score'],
                $variants['sharpness_score']
            ),
        ]);
    }

    public function rotate(Request $request, int $organizationId, int $mediaId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $row = $this->mediaRow($organization, $mediaId);
        $data = $request->validate(['degrees' => 'required|integer|in:90,180,270']);

        $source = $row->original_path ?: $row->path;
        abort_unless($source && Storage::disk('public')->exists($source), 422, 'Arquivo original indisponível para rotação.');

        $rotation = ((int) ($row->rotation ?? 0) + (int) $data['degrees']) % 360;
        $image = Image::make(Storage::disk('public')->path($source))->orientate()->rotate(-1 * $rotation);
        $width = $image->width();
        $height = $image->height();
        $base = 'images/apps/'.$this->context->slug().'/organizations/'.$organization->id.'/gallery/';
        $stem = Str::slug($organization->name) ?: 'organization';
        $newPath = $base.$stem.'-'.Str::uuid().'.webp';
        $thumbPath = $base.'thumbs/'.$stem.'-'.Str::uuid().'.webp';

        $this->ensureDirectory(Storage::disk('public')->path($newPath));
        $this->ensureDirectory(Storage::disk('public')->path($thumbPath));

        $main = clone $image;
        $main->resize(2000, 1500, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        })->encode('webp', 86)->save(Storage::disk('public')->path($newPath));

        $thumb = clone $image;
        $thumb->resize(720, 720, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        })->encode('webp', 82)->save(Storage::disk('public')->path($thumbPath));

        $metrics = $this->analyzeImage(clone $image);
        $oldPath = $row->path;
        $oldThumb = $row->thumbnail_path;
        DB::table('organization_media')->where('id', $row->id)->update([
            'path' => $newPath,
            'thumbnail_path' => $thumbPath,
            'width' => $width,
            'height' => $height,
            'perceptual_hash' => $metrics['perceptual_hash'],
            'brightness_score' => $metrics['brightness_score'],
            'sharpness_score' => $metrics['sharpness_score'],
            'cover_score' => $this->coverScore($width, $height, $metrics['brightness_score'], $metrics['sharpness_score']),
            'processing_version' => 2,
            'last_processed_at' => now(),
            'rotation' => $rotation,
            'updated_by_user_id' => $request->user()->id,
            'updated_at' => now(),
        ]);
        foreach ([$oldPath, $oldThumb] as $path) {
            if ($path && $path !== $source) Storage::disk('public')->delete($path);
        }

        $this->track($organization, $request, 'production_media.rotate', [
            'media_id' => $row->id,
            'degrees' => (int) $data['degrees'],
        ]);

        return response()->json([
            'message' => 'Foto rotacionada.',
            'media' => $this->mediaPayload(DB::table('organization_media')->where('id', $row->id)->first(), true),
        ]);
    }

    public function reorder(Request $request, int $organizationId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $data = $request->validate([
            'media_ids' => 'required|array|min:1|max:'.self::MAX_MEDIA,
            'media_ids.*' => 'required|integer|min:1|distinct',
        ]);

        $ids = collect($data['media_ids'])->map(fn ($id) => (int) $id)->values();
        $ownedIds = $this->activeMediaQuery($organization)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id);
        abort_unless($ownedIds->count() === $ids->count(), 422, 'A ordem contém fotos inválidas ou indisponíveis.');

        DB::transaction(function () use ($ids, $request) {
            foreach ($ids as $position => $id) {
                DB::table('organization_media')->where('id', $id)->update([
                    'position' => $position,
                    'updated_by_user_id' => $request->user()->id,
                    'updated_at' => now(),
                ]);
            }
        });

        $this->track($organization, $request, 'production_media.reorder', ['media_ids' => $ids->all()]);

        return response()->json([
            'message' => 'Ordem da galeria salva.',
            'media' => $this->activeMediaQuery($organization)->orderBy('position')->orderBy('id')->get()
                ->map(fn ($row) => $this->mediaPayload($row, true))->values(),
        ]);
    }

    public function bulkUpdate(Request $request, int $organizationId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $data = $request->validate([
            'media_ids' => 'required|array|min:1|max:'.self::MAX_MEDIA,
            'media_ids.*' => 'required|integer|min:1|distinct',
            'album_id' => 'sometimes|nullable|integer|min:1',
        ]);

        $ids = collect($data['media_ids'])->map(fn ($id) => (int) $id)->values();
        $rows = $this->activeMediaQuery($organization)->whereIn('id', $ids)->get();
        abort_unless($rows->count() === $ids->count(), 422, 'Uma ou mais fotos não estão disponíveis.');

        if (array_key_exists('album_id', $data)) {
            $this->assertAlbum($organization, $data['album_id']);
        }

        $updates = [
            'updated_by_user_id' => $request->user()->id,
            'updated_at' => now(),
        ];
        if (array_key_exists('album_id', $data)) {
            $updates['album_id'] = $data['album_id'];
        }

        DB::table('organization_media')->whereIn('id', $ids)->update($updates);

        $this->track($organization, $request, 'production_media.bulk_update', [
            'media_ids' => $ids->all(),
            'fields' => array_keys($updates),
        ]);

        return response()->json([
            'message' => 'Fotos atualizadas.',
            'media' => $this->activeMediaQuery($organization)->orderBy('position')->orderBy('id')->get()
                ->map(fn ($row) => $this->mediaPayload($row, true))->values(),
        ]);
    }

    public function bulkDelete(Request $request, int $organizationId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $data = $request->validate([
            'media_ids' => 'required|array|min:1|max:'.self::MAX_MEDIA,
            'media_ids.*' => 'required|integer|min:1|distinct',
            'reason' => 'nullable|string|max:500',
        ]);
        $ids = collect($data['media_ids'])->map(fn ($id) => (int) $id)->values();

        $rows = $this->activeMediaQuery($organization)->whereIn('id', $ids)->get();
        abort_unless($rows->count() === $ids->count(), 422, 'Uma ou mais fotos não estão disponíveis.');

        DB::table('organization_media')->whereIn('id', $ids)->update([
            'status' => 'deleted',
            'deleted_by_user_id' => $request->user()->id,
            'deleted_at' => now(),
            'updated_by_user_id' => $request->user()->id,
            'updated_at' => now(),
        ]);

        $this->normalizePositions($organization);
        $reason = trim((string) ($data['reason'] ?? '')) ?: null;
        $this->track($organization, $request, 'production_media.delete', [
            'media_ids' => $ids->all(),
            'bulk' => $ids->count() > 1,
            'reason' => $reason,
        ]);

        $actor = $request->user();
        $isAdminRemoval = $actor
            && (int) $actor->id !== (int) $organization->user_id
            && method_exists($actor, 'hasProfile')
            && $actor->hasProfile('Administrador');
        if ($isAdminRemoval && $organization->user_id) {
            try {
                $this->notifications->sendToUser($this->context->id(), (int) $organization->user_id, [
                    'type' => 'production_media_moderated',
                    'title' => 'Foto removida da galeria',
                    'message' => $reason
                        ? 'Uma foto de '.$organization->name.' foi removida pela moderação. Motivo: '.$reason
                        : 'Uma foto de '.$organization->name.' foi removida pela moderação.',
                    'reference_type' => 'production',
                    'reference_id' => $organization->id,
                    'reference_url' => '/production/edit/'.$organization->id.'#production-editor-gallery',
                    'data' => [
                        'organization_id' => $organization->id,
                        'media_ids' => $ids->all(),
                        'reason' => $reason,
                    ],
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'message' => $ids->count() === 1 ? 'Foto removida.' : $ids->count().' fotos removidas.',
            'deleted_ids' => $ids->all(),
            'undo_until' => now()->addHours(self::RESTORE_WINDOW_HOURS)->toIso8601String(),
        ]);
    }

    public function delete(Request $request, int $organizationId, int $mediaId)
    {
        $request->merge(['media_ids' => [$mediaId]]);
        return $this->bulkDelete($request, $organizationId);
    }

    public function restore(Request $request, int $organizationId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $data = $request->validate([
            'media_ids' => 'required|array|min:1|max:'.self::MAX_MEDIA,
            'media_ids.*' => 'required|integer|min:1|distinct',
        ]);
        $ids = collect($data['media_ids'])->map(fn ($id) => (int) $id)->values();

        $rows = DB::table('organization_media')
            ->where('app_id', $this->context->id())
            ->where('organization_id', $organization->id)
            ->whereIn('id', $ids)
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '>=', now()->subHours(self::RESTORE_WINDOW_HOURS))
            ->get();
        abort_unless($rows->count() === $ids->count(), 422, 'Uma ou mais fotos não podem mais ser restauradas.');

        $start = (int) ($this->activeMediaQuery($organization)->max('position') ?? -1) + 1;
        DB::transaction(function () use ($rows, $request, $start) {
            foreach ($rows->values() as $index => $row) {
                DB::table('organization_media')->where('id', $row->id)->update([
                    'status' => 'published',
                    'deleted_by_user_id' => null,
                    'deleted_at' => null,
                    'position' => $start + $index,
                    'updated_by_user_id' => $request->user()->id,
                    'updated_at' => now(),
                ]);
            }
        });

        $this->track($organization, $request, 'production_media.restore', ['media_ids' => $ids->all()]);

        return response()->json([
            'message' => $ids->count() === 1 ? 'Foto restaurada.' : 'Fotos restauradas.',
            'media' => $this->activeMediaQuery($organization)->orderBy('position')->orderBy('id')->get()
                ->map(fn ($row) => $this->mediaPayload($row, true))->values(),
        ]);
    }

    public function setCover(Request $request, int $organizationId, int $mediaId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $row = $this->mediaRow($organization, $mediaId);
        abort_unless($row->path && Storage::disk('public')->exists($row->path), 422, 'A foto não está disponível.');

        $path = 'images/apps/'.$this->context->slug().'/organizations/background-'.Str::uuid().'.webp';
        $absolute = Storage::disk('public')->path($path);
        $this->ensureDirectory($absolute);
        Image::make(Storage::disk('public')->path($row->path))
            ->orientate()
            ->fit(1920, 700)
            ->encode('webp', 86)
            ->save($absolute);

        $previous = $organization->background;
        $organization->background = $path;
        $organization->updated_by = $request->user()->id;
        $organization->save();

        $safePrefix = 'images/apps/'.$this->context->slug().'/organizations/background-';
        if ($previous && $previous !== $path && str_starts_with($previous, $safePrefix)) {
            Storage::disk('public')->delete($previous);
        }

        $this->track($organization, $request, 'production_media.cover', ['media_id' => $row->id]);

        return response()->json([
            'message' => 'Foto definida como capa da produção.',
            'background' => Storage::disk('public')->url($path),
            'path' => $path,
        ]);
    }

    public function importCover(Request $request, int $organizationId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        abort_if($this->activeMediaQuery($organization)->count() >= self::MAX_MEDIA, 422, 'A galeria pode ter até '.self::MAX_MEDIA.' fotos.');

        $source = (string) ($organization->background ?? '');
        abort_unless($source && ! preg_match('#^https?://#i', $source) && Storage::disk('public')->exists($source), 422, 'A capa atual não está disponível para importar.');

        $absoluteSource = Storage::disk('public')->path($source);
        $checksum = hash_file('sha256', $absoluteSource);
        $duplicate = $this->activeMediaQuery($organization)->where('checksum', $checksum)->first();
        if ($duplicate) {
            return response()->json([
                'message' => 'A capa já está presente na galeria.',
                'media' => $this->mediaPayload($duplicate, true),
                'duplicate_of' => (int) $duplicate->id,
            ]);
        }

        $image = Image::make($absoluteSource)->orientate();
        $width = $image->width();
        $height = $image->height();
        $base = 'images/apps/'.$this->context->slug().'/organizations/'.$organization->id.'/gallery/';
        $stem = Str::slug($organization->name) ?: 'organization';
        $uuid = (string) Str::uuid();
        $path = $base.$stem.'-'.$uuid.'.webp';
        $thumbPath = $base.'thumbs/'.$stem.'-'.$uuid.'.webp';
        $originalPath = $base.'originals/'.$stem.'-'.$uuid.'.webp';

        foreach ([$path, $thumbPath, $originalPath] as $target) {
            $this->ensureDirectory(Storage::disk('public')->path($target));
        }

        Storage::disk('public')->copy($source, $originalPath);

        $main = clone $image;
        $main->resize(2000, 1500, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        })->encode('webp', 86)->save(Storage::disk('public')->path($path));

        $thumb = clone $image;
        $thumb->resize(720, 720, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        })->encode('webp', 82)->save(Storage::disk('public')->path($thumbPath));

        $metrics = $this->analyzeImage(clone $image);
        $position = (int) ($this->activeMediaQuery($organization)->max('position') ?? -1) + 1;
        $id = DB::table('organization_media')->insertGetId([
            'app_id' => $this->context->id(),
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'path' => $path,
            'original_path' => $originalPath,
            'thumbnail_path' => $thumbPath,
            'caption' => 'Capa da produção',
            'alt_text' => $organization->name.' - capa da produção',
            'is_featured' => false,
            'status' => 'published',
            'original_name' => basename($source),
            'mime_type' => 'image/webp',
            'file_size' => Storage::disk('public')->size($path),
            'width' => $width,
            'height' => $height,
            'checksum' => $checksum,
            'perceptual_hash' => $metrics['perceptual_hash'],
            'brightness_score' => $metrics['brightness_score'],
            'sharpness_score' => $metrics['sharpness_score'],
            'cover_score' => $this->coverScore($width, $height, $metrics['brightness_score'], $metrics['sharpness_score']),
            'processing_version' => 2,
            'last_processed_at' => now(),
            'focal_x' => 50,
            'focal_y' => 50,
            'rotation' => 0,
            'position' => $position,
            'updated_by_user_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->track($organization, $request, 'production_media.import_cover', ['media_id' => $id]);

        return response()->json([
            'message' => 'Capa adicionada à galeria.',
            'media' => $this->mediaPayload(DB::table('organization_media')->where('id', $id)->first(), true),
        ], 201);
    }

    public function albums(Request $request, int $organizationId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        return response()->json([
            'albums' => DB::table('organization_media_albums')
                ->where('app_id', $this->context->id())
                ->where('organization_id', $organization->id)
                ->orderBy('position')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'position']),
        ]);
    }

    public function storeAlbum(Request $request, int $organizationId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $data = $request->validate(['name' => 'required|string|min:2|max:100']);
        $name = trim($data['name']);
        $base = Str::slug($name) ?: 'album';
        $slug = $base;
        $i = 2;
        while (DB::table('organization_media_albums')->where([
            'app_id' => $this->context->id(),
            'organization_id' => $organization->id,
            'slug' => $slug,
        ])->exists()) {
            $slug = $base.'-'.$i++;
        }

        $position = (int) (DB::table('organization_media_albums')
            ->where('app_id', $this->context->id())
            ->where('organization_id', $organization->id)
            ->max('position') ?? -1) + 1;

        $id = DB::table('organization_media_albums')->insertGetId([
            'app_id' => $this->context->id(),
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'name' => $name,
            'slug' => $slug,
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->track($organization, $request, 'production_media.album_create', ['album_id' => $id]);

        return response()->json([
            'message' => 'Álbum criado.',
            'album' => DB::table('organization_media_albums')->where('id', $id)->first(['id', 'name', 'slug', 'position']),
        ], 201);
    }

    public function deleteAlbum(Request $request, int $organizationId, int $albumId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $album = DB::table('organization_media_albums')
            ->where('app_id', $this->context->id())
            ->where('organization_id', $organization->id)
            ->where('id', $albumId)
            ->first();
        abort_unless($album, 404, 'Álbum não encontrado.');

        DB::transaction(function () use ($album) {
            DB::table('organization_media')->where('album_id', $album->id)->update(['album_id' => null, 'updated_at' => now()]);
            DB::table('organization_media_albums')->where('id', $album->id)->delete();
        });

        $this->track($organization, $request, 'production_media.album_delete', ['album_id' => $albumId]);
        return response()->json(['message' => 'Álbum removido. As fotos continuam na galeria.']);
    }

    public function crop(Request $request, int $organizationId, int $mediaId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $row = $this->mediaRow($organization, $mediaId);
        $data = $request->validate([
            'aspect' => 'required|in:square,portrait,landscape,cover',
            'zoom' => 'nullable|integer|min:100|max:300',
            'focal_x' => 'nullable|integer|min:0|max:100',
            'focal_y' => 'nullable|integer|min:0|max:100',
        ]);

        $source = $row->original_path ?: $row->path;
        abort_unless($source && Storage::disk('public')->exists($source), 422, 'Arquivo original indisponível para recorte.');

        $image = Image::make(Storage::disk('public')->path($source))->orientate();
        $sourceWidth = max(1, $image->width());
        $sourceHeight = max(1, $image->height());
        $ratios = [
            'square' => 1.0,
            'portrait' => 4 / 5,
            'landscape' => 4 / 3,
            'cover' => 1920 / 700,
        ];
        $targetRatio = $ratios[$data['aspect']];
        $sourceRatio = $sourceWidth / $sourceHeight;

        if ($sourceRatio > $targetRatio) {
            $baseHeight = $sourceHeight;
            $baseWidth = (int) round($baseHeight * $targetRatio);
        } else {
            $baseWidth = $sourceWidth;
            $baseHeight = (int) round($baseWidth / $targetRatio);
        }

        $zoom = max(1, ((int) ($data['zoom'] ?? 100)) / 100);
        $cropWidth = max(1, min($sourceWidth, (int) round($baseWidth / $zoom)));
        $cropHeight = max(1, min($sourceHeight, (int) round($baseHeight / $zoom)));
        $focalX = (int) ($data['focal_x'] ?? $row->focal_x ?? 50);
        $focalY = (int) ($data['focal_y'] ?? $row->focal_y ?? 50);
        $centerX = ($focalX / 100) * $sourceWidth;
        $centerY = ($focalY / 100) * $sourceHeight;
        $left = (int) round(max(0, min($sourceWidth - $cropWidth, $centerX - ($cropWidth / 2))));
        $top = (int) round(max(0, min($sourceHeight - $cropHeight, $centerY - ($cropHeight / 2))));

        $image->crop($cropWidth, $cropHeight, $left, $top);
        $paths = $this->saveProcessedVariants($organization, $image, 'crop');
        $metrics = $this->analyzeImage(clone $image);

        $oldPath = $row->path;
        $oldThumb = $row->thumbnail_path;
        DB::table('organization_media')->where('id', $row->id)->update([
            'path' => $paths['path'],
            'thumbnail_path' => $paths['thumbnail_path'],
            'width' => $cropWidth,
            'height' => $cropHeight,
            'perceptual_hash' => $metrics['perceptual_hash'],
            'brightness_score' => $metrics['brightness_score'],
            'sharpness_score' => $metrics['sharpness_score'],
            'cover_score' => $this->coverScore($cropWidth, $cropHeight, $metrics['brightness_score'], $metrics['sharpness_score']),
            'processing_version' => 2,
            'last_processed_at' => now(),
            'focal_x' => 50,
            'focal_y' => 50,
            'rotation' => 0,
            'updated_by_user_id' => $request->user()->id,
            'updated_at' => now(),
        ]);

        foreach (array_unique(array_filter([$oldPath, $oldThumb])) as $old) {
            if ($old !== $source) Storage::disk('public')->delete($old);
        }

        $this->track($organization, $request, 'production_media.crop', [
            'media_id' => $row->id,
            'aspect' => $data['aspect'],
            'zoom' => (int) ($data['zoom'] ?? 100),
        ]);

        return response()->json([
            'message' => 'Recorte aplicado sem alterar o arquivo original.',
            'media' => $this->mediaPayload(DB::table('organization_media')->where('id', $row->id)->first(), true),
        ]);
    }

    public function reprocess(Request $request, int $organizationId, int $mediaId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $row = $this->mediaRow($organization, $mediaId);
        $source = $row->original_path ?: $row->path;
        abort_unless($source && Storage::disk('public')->exists($source), 422, 'Arquivo original indisponível para reprocessamento.');

        $image = Image::make(Storage::disk('public')->path($source))->orientate();
        $width = $image->width();
        $height = $image->height();
        $paths = $this->saveProcessedVariants($organization, $image, 'reprocessed');
        $metrics = $this->analyzeImage(clone $image);

        $oldPath = $row->path;
        $oldThumb = $row->thumbnail_path;
        DB::table('organization_media')->where('id', $row->id)->update([
            'path' => $paths['path'],
            'thumbnail_path' => $paths['thumbnail_path'],
            'width' => $width,
            'height' => $height,
            'perceptual_hash' => $metrics['perceptual_hash'],
            'brightness_score' => $metrics['brightness_score'],
            'sharpness_score' => $metrics['sharpness_score'],
            'cover_score' => $this->coverScore($width, $height, $metrics['brightness_score'], $metrics['sharpness_score']),
            'processing_version' => 2,
            'last_processed_at' => now(),
            'rotation' => 0,
            'updated_by_user_id' => $request->user()->id,
            'updated_at' => now(),
        ]);

        foreach (array_unique(array_filter([$oldPath, $oldThumb])) as $old) {
            if ($old !== $source) Storage::disk('public')->delete($old);
        }

        $this->track($organization, $request, 'production_media.reprocess', ['media_id' => $row->id]);

        return response()->json([
            'message' => 'Imagem reprocessada e otimizada.',
            'media' => $this->mediaPayload(DB::table('organization_media')->where('id', $row->id)->first(), true),
        ]);
    }

    public function recommendations(Request $request, int $organizationId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $this->refreshLegacyMetrics($organization);

        $items = $this->activeMediaQuery($organization)
            ->orderByDesc('cover_score')
            ->orderBy('position')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $payload = $this->mediaPayload($row, true);
                $payload['recommendation'] = [
                    'score' => (int) ($row->cover_score ?? 0),
                    'reasons' => $this->coverReasons($row),
                ];
                return $payload;
            })
            ->values();

        return response()->json([
            'message' => $items->isEmpty() ? 'Adicione fotos para receber sugestões.' : 'Sugestões calculadas pela qualidade visual, resolução e enquadramento.',
            'recommendations' => $items,
        ]);
    }

    public function similar(Request $request, int $organizationId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $this->refreshLegacyMetrics($organization);
        $rows = $this->activeMediaQuery($organization)
            ->whereNotNull('perceptual_hash')
            ->orderBy('position')
            ->get();

        $pairs = [];
        for ($left = 0; $left < $rows->count(); $left++) {
            for ($right = $left + 1; $right < $rows->count(); $right++) {
                $distance = $this->hashDistance($rows[$left]->perceptual_hash, $rows[$right]->perceptual_hash);
                if ($distance !== null && $distance <= 8) {
                    $pairs[] = [
                        'distance' => $distance,
                        'similarity' => max(0, 100 - (int) round(($distance / 64) * 100)),
                        'left' => $this->mediaPayload($rows[$left], true),
                        'right' => $this->mediaPayload($rows[$right], true),
                    ];
                }
            }
        }

        usort($pairs, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return response()->json([
            'pairs' => array_slice($pairs, 0, 20),
            'count' => count($pairs),
        ]);
    }

    public function report(Request $request, string $slug, int $mediaId)
    {
        $data = $request->validate([
            'reason' => 'required|in:inappropriate,fraud,misleading,copyright,privacy,other',
            'details' => 'nullable|string|max:1000',
        ]);

        $organization = Production::query()
            ->where('app_id', $this->context->id())
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->firstOrFail();

        $media = $this->activeMediaQuery($organization)->where('id', $mediaId)->first();
        abort_unless($media, 404, 'Foto não encontrada.');

        $user = $request->user();
        $existing = DB::table('organization_media_reports')
            ->where('app_id', $this->context->id())
            ->where('media_id', $media->id)
            ->where('reporter_user_id', $user->id)
            ->whereIn('status', ['open', 'reviewing'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Sua denúncia desta foto já está em análise.',
                'report_id' => (int) $existing->id,
            ]);
        }

        $id = DB::table('organization_media_reports')->insertGetId([
            'app_id' => $this->context->id(),
            'organization_id' => $organization->id,
            'media_id' => $media->id,
            'reporter_user_id' => $user->id,
            'reason' => $data['reason'],
            'details' => trim((string) ($data['details'] ?? '')) ?: null,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->track($organization, $request, 'production_media.report', [
            'media_id' => (int) $media->id,
            'report_id' => $id,
            'reason' => $data['reason'],
        ]);

        return response()->json([
            'message' => 'Denúncia enviada para análise.',
            'report_id' => $id,
        ], 201);
    }

    public function moderationReports(Request $request)
    {
        $this->requireModerator($request);
        $data = $request->validate([
            'status' => 'nullable|in:open,reviewing,resolved,dismissed',
            'q' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $query = DB::table('organization_media_reports as reports')
            ->join('organization_media as media', 'media.id', '=', 'reports.media_id')
            ->join('establishments as organizations', 'organizations.id', '=', 'reports.organization_id')
            ->join('users as reporter', 'reporter.id', '=', 'reports.reporter_user_id')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'reports.reviewed_by_user_id')
            ->where('reports.app_id', $this->context->id())
            ->select([
                'reports.id',
                'reports.organization_id',
                'reports.media_id',
                'reports.reason',
                'reports.details',
                'reports.status',
                'reports.moderation_note',
                'reports.reviewed_at',
                'reports.created_at',
                'organizations.name as organization_name',
                'organizations.slug as organization_slug',
                'media.path as media_path',
                'media.caption as media_caption',
                'reporter.first_name as reporter_first_name',
                'reporter.last_name as reporter_last_name',
                'reporter.email as reporter_email',
                'reviewer.first_name as reviewer_first_name',
                'reviewer.last_name as reviewer_last_name',
            ]);

        if (! empty($data['status'])) {
            $query->where('reports.status', $data['status']);
        }

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $query->where(function ($search) use ($term) {
                $search->where('organizations.name', 'like', '%'.$term.'%')
                    ->orWhere('media.caption', 'like', '%'.$term.'%')
                    ->orWhere('reports.details', 'like', '%'.$term.'%')
                    ->orWhere('reporter.email', 'like', '%'.$term.'%');
            });
        }

        $reports = $query
            ->orderByRaw("CASE reports.status WHEN 'open' THEN 0 WHEN 'reviewing' THEN 1 WHEN 'resolved' THEN 2 ELSE 3 END")
            ->orderByDesc('reports.created_at')
            ->paginate((int) ($data['per_page'] ?? 25));

        $reports->getCollection()->transform(function ($report) {
            $report->media_url = $report->media_path ? Storage::disk('public')->url($report->media_path) : null;
            unset($report->media_path);
            return $report;
        });

        return response()->json([
            'reports' => $reports,
            'counts' => [
                'open' => DB::table('organization_media_reports')->where(['app_id' => $this->context->id(), 'status' => 'open'])->count(),
                'reviewing' => DB::table('organization_media_reports')->where(['app_id' => $this->context->id(), 'status' => 'reviewing'])->count(),
                'resolved' => DB::table('organization_media_reports')->where(['app_id' => $this->context->id(), 'status' => 'resolved'])->count(),
                'dismissed' => DB::table('organization_media_reports')->where(['app_id' => $this->context->id(), 'status' => 'dismissed'])->count(),
            ],
        ]);
    }

    public function reviewReport(Request $request, int $reportId)
    {
        $this->requireModerator($request);
        $data = $request->validate([
            'status' => 'required|in:open,reviewing,resolved,dismissed',
            'moderation_note' => 'nullable|string|max:2000',
            'remove_media' => 'sometimes|boolean',
        ]);

        $report = DB::table('organization_media_reports')
            ->where('app_id', $this->context->id())
            ->where('id', $reportId)
            ->first();
        abort_unless($report, 404, 'Denúncia não encontrada.');

        $organization = Production::query()
            ->where('app_id', $this->context->id())
            ->findOrFail($report->organization_id);

        DB::transaction(function () use ($request, $data, $report) {
            DB::table('organization_media_reports')->where('id', $report->id)->update([
                'status' => $data['status'],
                'moderation_note' => trim((string) ($data['moderation_note'] ?? '')) ?: null,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            if (! empty($data['remove_media'])) {
                DB::table('organization_media')
                    ->where('app_id', $this->context->id())
                    ->where('organization_id', $report->organization_id)
                    ->where('id', $report->media_id)
                    ->whereNull('deleted_at')
                    ->update([
                        'status' => 'deleted',
                        'deleted_by_user_id' => $request->user()->id,
                        'deleted_at' => now(),
                        'updated_by_user_id' => $request->user()->id,
                        'updated_at' => now(),
                    ]);
            }
        });

        if (! empty($data['remove_media'])) {
            $this->normalizePositions($organization);
            $note = trim((string) ($data['moderation_note'] ?? '')) ?: 'A imagem não atende às diretrizes da plataforma.';
            if ($organization->user_id) {
                try {
                    $this->notifications->sendToUser($this->context->id(), (int) $organization->user_id, [
                        'type' => 'production_media_moderated',
                        'title' => 'Foto removida da galeria',
                        'message' => 'Uma foto de '.$organization->name.' foi removida pela moderação. Motivo: '.$note,
                        'reference_type' => 'production',
                        'reference_id' => $organization->id,
                        'reference_url' => '/production/edit/'.$organization->id.'#production-editor-gallery',
                        'data' => [
                            'organization_id' => $organization->id,
                            'media_id' => (int) $report->media_id,
                            'report_id' => (int) $report->id,
                            'reason' => $note,
                        ],
                    ]);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        $this->track($organization, $request, 'production_media.report_review', [
            'media_id' => (int) $report->media_id,
            'report_id' => (int) $report->id,
            'status' => $data['status'],
            'removed' => (bool) ($data['remove_media'] ?? false),
        ]);

        return response()->json([
            'message' => 'Denúncia atualizada.',
            'report' => DB::table('organization_media_reports')->where('id', $report->id)->first(),
        ]);
    }

    private function storeImageVariants(Production $organization, UploadedFile $file): array
    {
        $image = Image::make($file->getRealPath())->orientate();
        $width = $image->width();
        $height = $image->height();
        $base = 'images/apps/'.$this->context->slug().'/organizations/'.$organization->id.'/gallery/';
        $stem = Str::slug($organization->name) ?: 'organization';
        $uuid = (string) Str::uuid();
        $path = $base.$stem.'-'.$uuid.'.webp';
        $thumbPath = $base.'thumbs/'.$stem.'-'.$uuid.'.webp';

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'jpg';
        $originalPath = $base.'originals/'.$stem.'-'.$uuid.'.'.$extension;

        $this->ensureDirectory(Storage::disk('public')->path($path));
        $this->ensureDirectory(Storage::disk('public')->path($thumbPath));
        $this->ensureDirectory(Storage::disk('public')->path($originalPath));
        Storage::disk('public')->put($originalPath, file_get_contents($file->getRealPath()));

        $main = clone $image;
        $main->resize(2000, 1500, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        })->encode('webp', 86)->save(Storage::disk('public')->path($path));

        $thumb = clone $image;
        $thumb->resize(720, 720, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        })->encode('webp', 82)->save(Storage::disk('public')->path($thumbPath));

        $metrics = $this->analyzeImage(clone $image);

        return [
            'path' => $path,
            'original_path' => $originalPath,
            'thumbnail_path' => $thumbPath,
            'width' => $width,
            'height' => $height,
            'perceptual_hash' => $metrics['perceptual_hash'],
            'brightness_score' => $metrics['brightness_score'],
            'sharpness_score' => $metrics['sharpness_score'],
            'cover_score' => $this->coverScore($width, $height, $metrics['brightness_score'], $metrics['sharpness_score']),
        ];
    }

    private function saveProcessedVariants(Production $organization, $image, string $suffix): array
    {
        $base = 'images/apps/'.$this->context->slug().'/organizations/'.$organization->id.'/gallery/';
        $stem = Str::slug($organization->name) ?: 'organization';
        $uuid = (string) Str::uuid();
        $safeSuffix = preg_replace('/[^a-z0-9-]/', '', strtolower($suffix)) ?: 'processed';
        $path = $base.$stem.'-'.$safeSuffix.'-'.$uuid.'.webp';
        $thumbPath = $base.'thumbs/'.$stem.'-'.$safeSuffix.'-'.$uuid.'.webp';

        $this->ensureDirectory(Storage::disk('public')->path($path));
        $this->ensureDirectory(Storage::disk('public')->path($thumbPath));

        $main = clone $image;
        $main->resize(2000, 1500, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        })->encode('webp', 86)->save(Storage::disk('public')->path($path));

        $thumb = clone $image;
        $thumb->resize(720, 720, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        })->encode('webp', 82)->save(Storage::disk('public')->path($thumbPath));

        return ['path' => $path, 'thumbnail_path' => $thumbPath];
    }

    private function analyzeImage($image): array
    {
        try {
            $sample = clone $image;
            $sample->resize(64, 64);
            $brightnessTotal = 0.0;
            $sharpnessTotal = 0.0;
            $brightnessSamples = 0;
            $sharpnessSamples = 0;

            for ($y = 0; $y < 64; $y += 4) {
                for ($x = 0; $x < 64; $x += 4) {
                    $current = $this->pixelLuminance($sample->pickColor($x, $y, 'array'));
                    $brightnessTotal += $current;
                    $brightnessSamples++;

                    if ($x + 4 < 64) {
                        $sharpnessTotal += abs($current - $this->pixelLuminance($sample->pickColor($x + 4, $y, 'array')));
                        $sharpnessSamples++;
                    }
                    if ($y + 4 < 64) {
                        $sharpnessTotal += abs($current - $this->pixelLuminance($sample->pickColor($x, $y + 4, 'array')));
                        $sharpnessSamples++;
                    }
                }
            }

            $brightnessRaw = $brightnessSamples > 0 ? $brightnessTotal / $brightnessSamples : 127;
            $sharpnessRaw = $sharpnessSamples > 0 ? $sharpnessTotal / $sharpnessSamples : 0;
            $brightnessScore = max(0, min(100, (int) round(($brightnessRaw / 255) * 100)));
            $sharpnessScore = max(0, min(100, (int) round($sharpnessRaw * 3.5)));

            $hashImage = clone $image;
            $hashImage->resize(9, 8)->greyscale();
            $bits = '';
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $left = $this->pixelLuminance($hashImage->pickColor($x, $y, 'array'));
                    $right = $this->pixelLuminance($hashImage->pickColor($x + 1, $y, 'array'));
                    $bits .= $left > $right ? '1' : '0';
                }
            }

            $hash = '';
            for ($index = 0; $index < 64; $index += 4) {
                $hash .= dechex(bindec(substr($bits, $index, 4)));
            }

            return [
                'perceptual_hash' => $hash,
                'brightness_score' => $brightnessScore,
                'sharpness_score' => $sharpnessScore,
            ];
        } catch (Throwable $e) {
            report($e);
            return [
                'perceptual_hash' => null,
                'brightness_score' => null,
                'sharpness_score' => null,
            ];
        }
    }

    private function pixelLuminance($color): float
    {
        if (! is_array($color)) {
            return 127.0;
        }

        $red = (float) ($color[0] ?? $color['r'] ?? 127);
        $green = (float) ($color[1] ?? $color['g'] ?? 127);
        $blue = (float) ($color[2] ?? $color['b'] ?? 127);

        return ($red * 0.2126) + ($green * 0.7152) + ($blue * 0.0722);
    }

    private function coverScore(int $width, int $height, ?int $brightness, ?int $sharpness): int
    {
        $ratio = $height > 0 ? $width / $height : 1;
        $resolution = min(35, (int) round((min($width, 2200) / 2200) * 20 + (min($height, 1200) / 1200) * 15));
        $ratioScore = max(0, 35 - (int) round(abs($ratio - 2.2) * 18));
        $brightnessScore = $brightness === null ? 10 : max(0, 15 - (int) round(abs($brightness - 58) * 0.45));
        $sharpnessScore = $sharpness === null ? 5 : min(15, (int) round($sharpness * 0.15));

        return max(0, min(100, $resolution + $ratioScore + $brightnessScore + $sharpnessScore));
    }

    private function coverReasons(object $row): array
    {
        $reasons = [];
        $ratio = (int) ($row->height ?? 0) > 0 ? ((int) $row->width / (int) $row->height) : 1;

        if ((int) ($row->width ?? 0) >= 1400) $reasons[] = 'boa resolução';
        if ($ratio >= 1.6 && $ratio <= 3.0) $reasons[] = 'formato horizontal favorável';
        if ((int) ($row->sharpness_score ?? 0) >= 35) $reasons[] = 'bons detalhes';
        if ((int) ($row->brightness_score ?? 0) >= 28 && (int) ($row->brightness_score ?? 0) <= 82) $reasons[] = 'exposição equilibrada';
        if (! $reasons) $reasons[] = 'melhor combinação disponível';

        return $reasons;
    }

    private function findVisuallySimilar(Production $organization, ?string $hash): ?object
    {
        if (! $hash) return null;

        $rows = $this->activeMediaQuery($organization)
            ->whereNotNull('perceptual_hash')
            ->get(['id', 'perceptual_hash']);

        $best = null;
        $bestDistance = 65;
        foreach ($rows as $row) {
            $distance = $this->hashDistance($hash, $row->perceptual_hash);
            if ($distance !== null && $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $row;
            }
        }

        return $bestDistance <= 8 ? $best : null;
    }

    private function hashDistance(?string $left, ?string $right): ?int
    {
        $left = strtolower(trim((string) $left));
        $right = strtolower(trim((string) $right));
        if ($left === '' || $right === '' || strlen($left) !== strlen($right)) return null;

        $distance = 0;
        for ($index = 0; $index < strlen($left); $index++) {
            $xor = hexdec($left[$index]) ^ hexdec($right[$index]);
            $distance += substr_count(decbin($xor), '1');
        }

        return $distance;
    }

    private function refreshLegacyMetrics(Production $organization): void
    {
        $rows = $this->activeMediaQuery($organization)
            ->where(function ($query) {
                $query->whereNull('perceptual_hash')->orWhereNull('cover_score');
            })
            ->limit(self::MAX_MEDIA)
            ->get();

        foreach ($rows as $row) {
            $source = $row->original_path ?: $row->path;
            if (! $source || ! Storage::disk('public')->exists($source)) continue;

            try {
                $image = Image::make(Storage::disk('public')->path($source))->orientate();
                $metrics = $this->analyzeImage(clone $image);
                DB::table('organization_media')->where('id', $row->id)->update([
                    'perceptual_hash' => $metrics['perceptual_hash'],
                    'brightness_score' => $metrics['brightness_score'],
                    'sharpness_score' => $metrics['sharpness_score'],
                    'cover_score' => $this->coverScore($image->width(), $image->height(), $metrics['brightness_score'], $metrics['sharpness_score']),
                    'processing_version' => 2,
                    'last_processed_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function deleteStoredVariants(object $row): void
    {
        foreach (array_unique(array_filter([$row->path ?? null, $row->thumbnail_path ?? null, $row->original_path ?? null])) as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function qualityWarnings(int $width, int $height, ?int $brightness = null, ?int $sharpness = null): array
    {
        $warnings = [];
        if ($width < 900 || $height < 600) {
            $warnings[] = 'A imagem tem resolução baixa e pode perder qualidade em telas grandes.';
        }
        $ratio = $height > 0 ? $width / $height : 1;
        if ($ratio > 3.2 || $ratio < .32) {
            $warnings[] = 'O formato é muito estreito e pode exigir recorte na grade.';
        }
        if ($brightness !== null && $brightness < 20) {
            $warnings[] = 'A imagem parece escura; confira se os detalhes principais estão visíveis.';
        }
        if ($brightness !== null && $brightness > 92) {
            $warnings[] = 'A imagem parece muito clara; confira se há áreas estouradas.';
        }
        if ($sharpness !== null && $sharpness < 15) {
            $warnings[] = 'A imagem pode estar desfocada ou com poucos detalhes.';
        }
        return $warnings;
    }

    private function normalizePositions(Production $organization): void
    {
        $this->activeMediaQuery($organization)->orderBy('position')->orderBy('id')->pluck('id')->values()
            ->each(fn ($id, $position) => DB::table('organization_media')->where('id', $id)->update(['position' => $position]));
    }

    private function activeMediaQuery(Production $organization)
    {
        return DB::table('organization_media')
            ->where('app_id', $this->context->id())
            ->where('organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->where('status', 'published');
    }

    private function mediaRow(Production $organization, int $mediaId): object
    {
        $row = $this->activeMediaQuery($organization)->where('id', $mediaId)->first();
        abort_unless($row, 404, 'Foto não encontrada.');
        return $row;
    }

    private function assertAlbum(Production $organization, ?int $albumId): void
    {
        if (! $albumId) return;
        abort_unless(DB::table('organization_media_albums')
            ->where('app_id', $this->context->id())
            ->where('organization_id', $organization->id)
            ->where('id', $albumId)
            ->exists(), 422, 'Álbum inválido.');
    }

    private function mediaPayload(object $row, bool $includeOriginal = false): array
    {
        $payload = [
            'id' => (int) $row->id,
            'url' => Storage::disk('public')->url($row->path),
            'thumbnail_url' => ! empty($row->thumbnail_path) ? Storage::disk('public')->url($row->thumbnail_path) : Storage::disk('public')->url($row->path),
            'caption' => $row->caption,
            'alt_text' => $row->alt_text ?: $row->caption,
            'is_featured' => (bool) ($row->is_featured ?? false),
            'album_id' => $row->album_id ? (int) $row->album_id : null,
            'position' => (int) $row->position,
            'focal_x' => (int) ($row->focal_x ?? 50),
            'focal_y' => (int) ($row->focal_y ?? 50),
            'rotation' => (int) ($row->rotation ?? 0),
            'width' => $row->width ? (int) $row->width : null,
            'height' => $row->height ? (int) $row->height : null,
            'file_size' => $row->file_size ? (int) $row->file_size : null,
            'brightness_score' => isset($row->brightness_score) ? (int) $row->brightness_score : null,
            'sharpness_score' => isset($row->sharpness_score) ? (int) $row->sharpness_score : null,
            'cover_score' => isset($row->cover_score) ? (int) $row->cover_score : null,
            'processing_version' => isset($row->processing_version) ? (int) $row->processing_version : null,
            'last_processed_at' => $row->last_processed_at ?? null,
            'created_at' => $row->created_at ?? null,
            'updated_at' => $row->updated_at ?? null,
        ];

        if ($includeOriginal) {
            $payload['original_url'] = ! empty($row->original_path) ? Storage::disk('public')->url($row->original_path) : $payload['url'];
            $payload['original_name'] = $row->original_name ?? null;
            $payload['mime_type'] = $row->mime_type ?? null;
        }

        return $payload;
    }

    private function requireModerator(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user && method_exists($user, 'hasProfile') && $user->hasProfile('Administrador'),
            403,
            'Acesso restrito à moderação.'
        );
    }

    private function managedOrganization(Request $request, int $id): Production
    {
        $organization = Production::query()->where('app_id', $this->context->id())->findOrFail($id);
        $user = $request->user();
        $admin = $user && method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');
        $owner = $user && (int) $organization->user_id === (int) $user->id;
        $mediaManager = false;

        if ($user && ! $owner && ! $admin) {
            $membership = Employer::query()
                ->where('establishment_id', $organization->id)
                ->where('user_id', $user->id)
                ->first(['id', 'permissions']);

            $permissions = is_array($membership?->permissions) ? $membership->permissions : [];
            $mediaManager = in_array('media.manage', $permissions, true)
                || in_array('gallery.manage', $permissions, true);
        }

        abort_unless(
            $user && ($admin || $owner || $mediaManager),
            403,
            'Você não pode gerenciar a galeria desta organização.'
        );

        return $organization;
    }

    private function ensureDirectory(string $absolutePath): void
    {
        $directory = dirname($absolutePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    private function track(Production $organization, Request $request, string $type, array $content = []): void
    {
        try {
            Interaction::register($type, $organization, $request->user(), $content, 'Galeria da produção');
        } catch (Throwable $e) {
            report($e);
        }
    }
}
