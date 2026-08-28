<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class File extends Model
{
    use HasFactory;

    protected $table = 'files';

    protected $fillable = [
        'uuid', 'app_id', 'entity_id', 'entity_name', 'fileable_id', 'fileable_type',
        'original_name', 'extension', 'mime_type', 'file_size', 'content_hash',
        'type', 'storage', 'path', 'storage_path', 'public_url',
        'width', 'height', 'quality', 'color_profile', 'orientation',
        'duration', 'fps', 'bitrate', 'video_width', 'video_height', 'codec',
        'group', 'tags', 'sort_order', 'position', 'is_primary', 'processed',
        'variants', 'meta', 'visibility', 'visibility_scope', 'status',
        'locked', 'expires_at', 'usage_count', 'last_used_at', 'source', 'version',
        'checksum', 'download_count', 'last_downloaded_at', 'compressed',
        'compression_ratio', 'created_by', 'updated_by',
    ];

    protected $hidden = [
        'storage_path',
        'content_hash',
        'checksum',
    ];

    protected $casts = [
        'tags' => 'array',
        'variants' => 'array',
        'meta' => 'array',
        'is_primary' => 'boolean',
        'processed' => 'boolean',
        'locked' => 'boolean',
        'compressed' => 'boolean',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'last_downloaded_at' => 'datetime',
    ];

    protected $appends = ['metrics', 'interaction_summary'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function entity()
    {
        return $this->morphTo(__FUNCTION__, 'entity_name', 'entity_id');
    }

    public function fileable()
    {
        return $this->morphTo();
    }

    public function app()
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function interactions()
    {
        return $this->hasMany(Interaction::class, 'entity_id')
            ->where('entity_type', 'File');
    }

    public function views()
    {
        return $this->interactions()->where('interaction_type', 'view');
    }

    public function downloads()
    {
        return $this->interactions()->where('interaction_type', 'download');
    }

    public function getMetricsAttribute()
    {
        return Cache::remember("file_{$this->id}_metrics", 120, function () {
            $views = $this->views();
            $downloads = $this->downloads();
            $totalViews = $views->count();
            $uniqueUsers = $views->distinct('user_id')->count('user_id');
            $totalDownloads = $downloads->count();
            $uniqueDownloaders = $downloads->distinct('user_id')->count('user_id');
            $firstView = $views->min('created_at');

            if ($firstView && ! ($firstView instanceof Carbon)) {
                $firstView = Carbon::parse($firstView);
            }

            $daysActive = $firstView ? now()->diffInDays($firstView) + 1 : 1;

            return [
                'total_views' => $totalViews,
                'unique_users' => $uniqueUsers,
                'total_downloads' => $totalDownloads,
                'unique_downloaders' => $uniqueDownloaders,
                'avg_views_per_day' => round($totalViews / max($daysActive, 1), 2),
                'days_active' => $daysActive,
                'engagement_score' => round(
                    ($totalViews * 0.5) + ($uniqueUsers * 1.2) + ($totalDownloads * 1.5),
                    2
                ),
            ];
        });
    }

    public function getInteractionSummaryAttribute()
    {
        return Cache::remember("file_{$this->id}_summary", 120, function () {
            $views = $this->views()
                ->with('user:id,first_name,last_name,user_name,avatar')
                ->get();

            if ($views->isEmpty()) {
                return [
                    'total_views' => 0,
                    'unique_users' => 0,
                    'most_active_user' => null,
                    'last_view_user' => null,
                ];
            }

            $mostActive = $views->groupBy('user_id')->map(function ($group) {
                $user = $group->first()->user;

                return [
                    'user_id' => $user?->id,
                    'user_name' => $user?->user_name,
                    'name' => trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')),
                    'avatar' => $user?->avatar,
                    'total' => $group->count(),
                ];
            })->sortByDesc('total')->first();

            $lastView = $views->sortByDesc('created_at')->first()?->user;

            return [
                'total_views' => $views->count(),
                'unique_users' => $views->pluck('user_id')->filter()->unique()->count(),
                'most_active_user' => $mostActive,
                'last_view_user' => $lastView ? [
                    'user_id' => $lastView->id,
                    'user_name' => $lastView->user_name,
                    'name' => trim(($lastView->first_name ?? '') . ' ' . ($lastView->last_name ?? '')),
                    'avatar' => $lastView->avatar,
                ] : null,
            ];
        });
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public' && $this->status === 'active';
    }

    public function markAsUsed(): void
    {
        $this->increment('usage_count');
        $this->update(['last_used_at' => now()]);
    }

    public function incrementDownload(): void
    {
        $this->increment('download_count');
        $this->update(['last_downloaded_at' => now()]);
    }

    public function variant($name)
    {
        return $this->variants[$name] ?? null;
    }

    public function scopeVisible($query)
    {
        return $query->where('visibility', 'public')->where('status', 'active');
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public static function storeOne(
        $file,
        string $entityName,
        int $entityId,
        string $type,
        ?int $appId,
        int $createdBy
    ) {
        $extension = strtolower((string) $file->extension());
        $original = $file->getClientOriginalName();
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $size = $file->getSize() ?: 0;
        $uuid = (string) Str::uuid();
        $filename = "{$uuid}.{$extension}";
        $path = $file->storeAs("uploads/{$entityName}/{$entityId}", $filename, 'public');
        $absolute = Storage::disk('public')->path($path);

        return self::create([
            'uuid' => $uuid,
            'app_id' => $appId,
            'entity_name' => $entityName,
            'entity_id' => $entityId,
            'type' => $type,
            'original_name' => $original,
            'extension' => $extension,
            'mime_type' => $mime,
            'file_size' => $size,
            'content_hash' => is_file($absolute) ? hash_file('sha256', $absolute) : null,
            'storage' => 'public',
            'path' => $path,
            'storage_path' => $absolute,
            'public_url' => Storage::disk('public')->url($path),
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ]);
    }
}
