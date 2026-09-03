<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'establishment_id',
        'author_user_id',
        'type',
        'status',
        'title',
        'slug',
        'excerpt',
        'content',
        'category',
        'tags',
        'cluster',
        'search_intent',
        'cover_image',
        'og_image',
        'seo_title',
        'seo_description',
        'canonical_url',
        'related',
        'metadata',
        'scheduled_at',
        'published_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'related' => 'array',
        'metadata' => 'array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where(function (Builder $visibility) {
            $visibility
                ->where(function (Builder $published) {
                    $published->where('status', 'published')
                        ->where(function (Builder $date) {
                            $date->whereNull('published_at')->orWhere('published_at', '<=', now());
                        });
                })
                ->orWhere(function (Builder $scheduled) {
                    $scheduled->where('status', 'scheduled')
                        ->whereNotNull('scheduled_at')
                        ->where('scheduled_at', '<=', now());
                });
        });
    }

    public function scopeForApplication(Builder $query, Application|int|string|null $application): Builder
    {
        if ($application === null || $application === '') {
            return $query;
        }

        $id = $application instanceof Application
            ? $application->id
            : (is_numeric($application)
                ? (int) $application
                : Application::query()->where('slug', $application)->value('id'));

        if (! $id) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scope) use ($id) {
            $scope->whereNull('application_id')->orWhere('application_id', $id);
        });
    }
}
