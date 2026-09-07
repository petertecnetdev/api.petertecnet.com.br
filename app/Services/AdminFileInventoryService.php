<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Blog;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\File;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class AdminFileInventoryService
{
    private const AUTO_CLEAN_MIN_AGE_SECONDS = 3600;
    private const CLEANABLE_KINDS = ['event', 'avatar', 'managed_file'];

    public function snapshot(array $filters = []): array
    {
        $rows = $this->buildRows();
        $filtered = $this->filterRows($rows, $filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(200, max(20, (int) ($filters['per_page'] ?? 80)));
        $total = count($filtered);

        return [
            'summary' => $this->summary($rows),
            'filters' => [
                'states' => $this->distinctOptions($rows, 'state'),
                'kinds' => $this->distinctOptions($rows, 'kind'),
                'apps' => $this->appOptions($rows),
            ],
            'files' => array_slice($filtered, ($page - 1) * $perPage, $perPage),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'policy' => [
                'protected_categories' => ['blog', 'blogs', 'item', 'items', 'branding'],
                'automatic_cleanup_age_minutes' => 60,
                'automatic_cleanup_scope' => self::CLEANABLE_KINDS,
            ],
        ];
    }

    public function cleanup(array $selectedPaths = [], bool $allCandidates = false): array
    {
        $rows = $this->buildRows();
        $selected = collect($selectedPaths)
            ->map(fn ($path) => $this->normalizePath((string) $path))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $targets = array_values(array_filter($rows, function (array $row) use ($selected, $allCandidates) {
            if (! $row['cleanup_candidate']) return false;
            return $allCandidates || in_array($row['path'], $selected, true);
        }));

        $disk = Storage::disk('public');
        $deleted = [];
        $failed = [];
        $freed = 0;

        foreach ($targets as $row) {
            if ($row['protected'] || ! $row['cleanup_candidate']) {
                $failed[] = ['path' => $row['path'], 'reason' => 'Arquivo protegido ou fora da política de limpeza.'];
                continue;
            }

            try {
                if ($disk->exists($row['path'])) $disk->delete($row['path']);
                $deleted[] = $row['path'];
                $freed += (int) $row['file_size'];
            } catch (Throwable $exception) {
                $failed[] = ['path' => $row['path'], 'reason' => $exception->getMessage()];
            }
        }

        return [
            'deleted' => $deleted,
            'deleted_count' => count($deleted),
            'failed' => $failed,
            'freed_bytes' => $freed,
        ];
    }

    public function deleteRegistered(File $file): array
    {
        $path = $this->normalizePath((string) ($file->path ?: $file->public_url));
        $reference = $path ? ($this->referenceIndex()[$path] ?? null) : null;

        if ($this->isProtected($path, $file, $reference)) {
            return ['allowed' => false, 'reason' => 'Arquivos de blogs, itens e branding são protegidos contra exclusão pelo Admin Center.'];
        }
        if ((bool) $file->locked) {
            return ['allowed' => false, 'reason' => 'O arquivo está bloqueado para exclusão.'];
        }
        if ($reference) {
            return ['allowed' => false, 'reason' => 'O arquivo ainda está referenciado por um recurso ativo. Remova o vínculo antes de excluir.'];
        }

        $freed = 0;
        if ($file->storage !== 'external' && $path) {
            $disk = Storage::disk('public');
            if ($disk->exists($path)) {
                try {
                    $freed = (int) $disk->size($path);
                } catch (Throwable) {
                    $freed = (int) $file->file_size;
                }
                $disk->delete($path);
            }
        }

        $file->delete();
        return ['allowed' => true, 'freed_bytes' => $freed];
    }

    private function buildRows(): array
    {
        $disk = Storage::disk('public');
        $registered = File::query()->with('app:id,name,slug')->get();
        $registeredByPath = [];

        foreach ($registered as $file) {
            $path = $this->normalizePath((string) ($file->path ?: $file->public_url));
            if ($path) $registeredByPath[$path] = $file;
        }

        $references = $this->referenceIndex();
        $rows = [];
        $seenRegistered = [];
        $now = now()->timestamp;

        foreach ($disk->allFiles() as $rawPath) {
            $path = $this->normalizePath((string) $rawPath);
            if (! $path) continue;

            $file = $registeredByPath[$path] ?? null;
            if ($file) $seenRegistered[(int) $file->id] = true;
            $reference = $references[$path] ?? null;
            $protected = $this->isProtected($path, $file, $reference);
            $kind = $this->kindFor($path, $file);

            try { $size = (int) $disk->size($path); }
            catch (Throwable) { $size = (int) ($file?->file_size ?? 0); }

            try { $modifiedTimestamp = (int) $disk->lastModified($path); }
            catch (Throwable) { $modifiedTimestamp = $now; }

            $tracked = (bool) $file;
            $referenced = (bool) $reference;
            $state = $tracked ? 'tracked' : ($referenced ? 'referenced' : 'orphan');
            $candidate = ! $protected
                && ! $tracked
                && ! $referenced
                && in_array($kind, self::CLEANABLE_KINDS, true)
                && max(0, $now - $modifiedTimestamp) >= self::AUTO_CLEAN_MIN_AGE_SECONDS;

            $rows[] = $this->row($path, $file, $reference, $state, $kind, $size, $modifiedTimestamp, $protected, $candidate, true);
        }

        foreach ($registered as $file) {
            if (isset($seenRegistered[(int) $file->id])) continue;
            $path = $this->normalizePath((string) ($file->path ?: $file->public_url));
            $reference = $path ? ($references[$path] ?? null) : null;
            $external = $file->storage === 'external';

            $rows[] = $this->row(
                $path ?: (string) $file->path,
                $file,
                $reference,
                $external ? 'external' : 'missing',
                $this->kindFor($path, $file),
                (int) $file->file_size,
                optional($file->updated_at)->timestamp ?? $now,
                $this->isProtected($path, $file, $reference),
                false,
                $external,
            );
        }

        usort($rows, function (array $a, array $b) {
            $candidate = ((int) $b['cleanup_candidate']) <=> ((int) $a['cleanup_candidate']);
            if ($candidate !== 0) return $candidate;
            $missing = (($b['state'] === 'missing') ? 1 : 0) <=> (($a['state'] === 'missing') ? 1 : 0);
            if ($missing !== 0) return $missing;
            return ((int) $b['file_size']) <=> ((int) $a['file_size']);
        });

        return $rows;
    }

    private function row(string $path, ?File $file, ?array $reference, string $state, string $kind, int $size, int $modifiedTimestamp, bool $protected, bool $candidate, bool $exists): array
    {
        $appSlug = $file?->app?->slug ?: $this->appSlugFromPath($path);
        $appName = $file?->app?->name ?: ($appSlug ? Str::headline($appSlug) : null);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $isImage = str_starts_with((string) ($file?->mime_type ?? ''), 'image/')
            || in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'svg'], true);

        return [
            'key' => $file ? 'file:'.$file->id : 'path:'.sha1($path),
            'id' => $file?->id,
            'uuid' => $file?->uuid,
            'path' => $path,
            'public_url' => $file?->public_url ?: ($exists ? Storage::disk('public')->url($path) : null),
            'original_name' => $file?->original_name ?: basename($path),
            'mime_type' => $file?->mime_type,
            'extension' => $file?->extension ?: $extension,
            'file_size' => $size,
            'is_image' => $isImage,
            'storage' => $file?->storage ?: 'public',
            'app_id' => $file?->app_id,
            'app_slug' => $appSlug,
            'app_name' => $appName,
            'entity_id' => $file?->entity_id,
            'entity_name' => $file?->entity_name,
            'group' => $file?->group,
            'status' => $file?->status,
            'state' => $state,
            'kind' => $kind,
            'tracked' => (bool) $file,
            'referenced' => (bool) $reference,
            'reference_count' => (int) ($reference['count'] ?? 0),
            'reference_labels' => array_values($reference['labels'] ?? []),
            'exists' => $exists,
            'protected' => $protected,
            'protection_reason' => $protected ? $this->protectionReason($path, $file, $reference) : null,
            'cleanup_candidate' => $candidate,
            'can_delete_registered' => (bool) $file && ! $protected && ! $reference && ! (bool) $file->locked,
            'locked' => (bool) ($file?->locked ?? false),
            'usage_count' => (int) ($file?->usage_count ?? 0),
            'modified_at' => date(DATE_ATOM, $modifiedTimestamp),
        ];
    }

    private function referenceIndex(): array
    {
        $references = [];
        $this->collectModelReferences($references, User::class, ['avatar' => 'Avatar de usuário', 'background' => 'Capa de usuário']);
        $this->collectModelReferences($references, Event::class, ['image' => 'Imagem de evento']);
        $this->collectModelReferences($references, Establishment::class, ['logo' => 'Logo de estabelecimento', 'background' => 'Capa de estabelecimento', 'images' => 'Galeria de estabelecimento']);
        $this->collectModelReferences($references, Item::class, ['image' => 'Imagem de item'], true);
        $this->collectModelReferences($references, Blog::class, ['cover_image' => 'Imagem de blog'], true);
        $this->collectModelReferences($references, Application::class, ['logo' => 'Logo de aplicação', 'icon' => 'Ícone de aplicação', 'favicon' => 'Favicon de aplicação', 'social_image' => 'Imagem social de aplicação'], true);
        return $references;
    }

    private function collectModelReferences(array &$references, string $modelClass, array $fields, bool $protected = false): void
    {
        $model = new $modelClass();
        $table = $model->getTable();
        $available = array_values(array_filter(array_keys($fields), fn ($field) => Schema::hasColumn($table, $field)));
        if ($available === []) return;

        $query = $modelClass::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) $query->withTrashed();

        $query->select(array_merge(['id'], $available))->orderBy('id')->chunkById(500, function ($rows) use (&$references, $fields, $available, $protected, $modelClass) {
            foreach ($rows as $row) {
                foreach ($available as $field) {
                    foreach ($this->pathsFromValue($row->{$field}) as $path) {
                        $references[$path] ??= ['count' => 0, 'labels' => [], 'protected' => false];
                        $references[$path]['count']++;
                        $references[$path]['protected'] = $references[$path]['protected'] || $protected;
                        $references[$path]['labels'][] = ($fields[$field] ?? class_basename($modelClass)).' #'.$row->id;
                        $references[$path]['labels'] = array_values(array_unique($references[$path]['labels']));
                    }
                }
            }
        });
    }

    private function pathsFromValue(mixed $value): array
    {
        if ($value === null || $value === '') return [];
        if (is_array($value)) {
            $paths = [];
            array_walk_recursive($value, function ($item) use (&$paths) {
                if (! is_scalar($item)) return;
                $path = $this->normalizePath((string) $item);
                if ($path) $paths[] = $path;
            });
            return array_values(array_unique($paths));
        }
        if (is_object($value)) return $this->pathsFromValue((array) $value);

        $string = trim((string) $value);
        if ($string === '') return [];
        if (($string[0] ?? '') === '[' || ($string[0] ?? '') === '{') {
            $decoded = json_decode($string, true);
            if (json_last_error() === JSON_ERROR_NONE) return $this->pathsFromValue($decoded);
        }

        $path = $this->normalizePath($string);
        return $path ? [$path] : [];
    }

    private function normalizePath(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;

        $parsed = parse_url($value, PHP_URL_PATH);
        if (is_string($parsed) && $parsed !== '') $value = urldecode($parsed);
        $value = str_replace('\\', '/', $value);
        $storageRoot = str_replace('\\', '/', Storage::disk('public')->path(''));
        if (str_starts_with($value, $storageRoot)) $value = substr($value, strlen($storageRoot));
        $value = ltrim($value, '/');
        if (str_starts_with($value, 'storage/')) $value = substr($value, 8);
        if (str_starts_with($value, 'public/')) $value = substr($value, 7);

        if (! preg_match('#^(uploads|images|branding|optimized)/#i', $value)) return null;
        return trim($value, '/');
    }

    private function isProtected(?string $path, ?File $file, ?array $reference): bool
    {
        $entity = strtolower((string) $file?->entity_name);
        if (in_array($entity, ['blog', 'blogs', 'item', 'items'], true)) return true;
        if ((bool) ($reference['protected'] ?? false)) return true;
        if (! $path) return false;
        return (bool) preg_match('#(^|/)(blog|blogs|item|items|branding)(/|$)#i', $path);
    }

    private function protectionReason(?string $path, ?File $file, ?array $reference): string
    {
        $entity = strtolower((string) $file?->entity_name);
        if (in_array($entity, ['blog', 'blogs'], true) || ($path && preg_match('#(^|/)(blog|blogs)(/|$)#i', $path))) return 'Blog protegido';
        if (in_array($entity, ['item', 'items'], true) || ($path && preg_match('#(^|/)(item|items)(/|$)#i', $path))) return 'Item protegido';
        if ($path && preg_match('#(^|/)branding(/|$)#i', $path)) return 'Branding protegido';
        if ((bool) ($reference['protected'] ?? false)) return 'Recurso essencial protegido';
        return 'Protegido pela política de arquivos';
    }

    private function kindFor(?string $path, ?File $file): string
    {
        $path = strtolower((string) $path);
        $entity = strtolower((string) $file?->entity_name);
        if (str_contains($path, '/blog') || in_array($entity, ['blog', 'blogs'], true)) return 'blog';
        if (preg_match('#(^|/)(item|items)(/|$)#', $path) || in_array($entity, ['item', 'items'], true)) return 'item';
        if (preg_match('#(^|/)events?(/|$)#', $path) || $entity === 'event') return 'event';
        if (preg_match('#(^|/)avatar(/|$)#', $path) || str_starts_with($path, 'uploads/user/')) return 'avatar';
        if (str_starts_with($path, 'uploads/files/')) return 'managed_file';
        if (str_starts_with($path, 'branding/')) return 'branding';
        if (str_starts_with($path, 'optimized/')) return 'optimized';
        return $file?->type ?: 'media';
    }

    private function appSlugFromPath(string $path): ?string
    {
        return preg_match('#^images/apps/([^/]+)/#', $path, $match) ? $match[1] : null;
    }

    private function filterRows(array $rows, array $filters): array
    {
        $search = Str::lower(trim((string) ($filters['search'] ?? '')));
        $state = trim((string) ($filters['state'] ?? ''));
        $kind = trim((string) ($filters['kind'] ?? ''));
        $app = trim((string) ($filters['app'] ?? ''));
        $onlyCandidates = filter_var($filters['cleanup_candidate'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $onlyProtected = filter_var($filters['protected'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return array_values(array_filter($rows, function (array $row) use ($search, $state, $kind, $app, $onlyCandidates, $onlyProtected) {
            if ($state && $row['state'] !== $state) return false;
            if ($kind && $row['kind'] !== $kind) return false;
            if ($app && (string) ($row['app_id'] ?: $row['app_slug']) !== $app) return false;
            if ($onlyCandidates && ! $row['cleanup_candidate']) return false;
            if ($onlyProtected && ! $row['protected']) return false;
            if ($search !== '') {
                $haystack = Str::lower(implode(' ', [$row['original_name'], $row['path'], $row['entity_name'], $row['app_name'], $row['app_slug'], implode(' ', $row['reference_labels'])]));
                if (! str_contains($haystack, $search)) return false;
            }
            return true;
        }));
    }

    private function summary(array $rows): array
    {
        $physical = array_filter($rows, fn ($row) => $row['exists'] && $row['storage'] !== 'external');
        $candidate = array_filter($rows, fn ($row) => $row['cleanup_candidate']);
        $orphans = array_filter($rows, fn ($row) => $row['state'] === 'orphan');
        $missing = array_filter($rows, fn ($row) => $row['state'] === 'missing');
        $protected = array_filter($rows, fn ($row) => $row['protected']);
        $diskTotal = @disk_total_space('/') ?: 0;
        $diskFree = @disk_free_space('/') ?: 0;

        return [
            'disk_total_bytes' => (int) $diskTotal,
            'disk_used_bytes' => max(0, (int) $diskTotal - (int) $diskFree),
            'disk_free_bytes' => (int) $diskFree,
            'storage_public_bytes' => array_sum(array_column($physical, 'file_size')),
            'physical_files' => count($physical),
            'registered_files' => count(array_filter($rows, fn ($row) => $row['tracked'])),
            'orphan_files' => count($orphans),
            'orphan_bytes' => array_sum(array_column($orphans, 'file_size')),
            'cleanup_candidates' => count($candidate),
            'cleanup_candidate_bytes' => array_sum(array_column($candidate, 'file_size')),
            'missing_registered_files' => count($missing),
            'protected_files' => count($protected),
            'protected_bytes' => array_sum(array_column($protected, 'file_size')),
        ];
    }

    private function distinctOptions(array $rows, string $field): array
    {
        return collect($rows)->pluck($field)->filter()->unique()->sort()->values()->all();
    }

    private function appOptions(array $rows): array
    {
        return collect($rows)
            ->filter(fn ($row) => $row['app_id'] || $row['app_slug'])
            ->map(fn ($row) => [
                'id' => $row['app_id'],
                'slug' => $row['app_slug'],
                'name' => $row['app_name'] ?: $row['app_slug'],
                'value' => (string) ($row['app_id'] ?: $row['app_slug']),
            ])
            ->unique('value')
            ->sortBy('name')
            ->values()
            ->all();
    }
}
