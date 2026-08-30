<?php

namespace App\Models;

use App\Services\ApplicationContextService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Interaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'app_id', 'entity_id', 'entity_type', 'interaction_type', 'outcome', 'severity', 'environment',
        'route', 'method', 'session_key', 'request_id', 'correlation_id', 'parent_interaction_id',
        'comment', 'name', 'content',
    ];

    protected $casts = [
        'content' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Interaction $interaction) {
            $content = is_array($interaction->content) ? $interaction->content : [];
            $request = request();
            $context = app(ApplicationContextService::class)->describe($request, null, $content);

            $interaction->app_id ??= $context['application']?->id;
            $interaction->route ??= $request?->route()?->uri() ?: $request?->path();
            $interaction->method ??= $request?->method();
            $interaction->session_key ??= static::sessionKey($request?->userAgent(), $request?->ip());

            $interaction->content = array_filter(array_merge([
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'origin' => $context['origin'],
                'referer' => $context['referer'],
                'app_slug' => $context['application']?->slug,
                'app_name' => $context['application']?->name,
                'source_app_id' => $context['source_application']?->id,
                'source_app_slug' => $context['source_application']?->slug,
                'target_app_id' => $content['target_app_id'] ?? $content['app_id'] ?? $request?->input('app_id'),
                'application_context' => $context['resolution'],
                'declared_app' => $context['declared_app'],
            ], $content), fn ($value) => $value !== null && $value !== '');
        });
    }

    public function user() { return $this->belongsTo(User::class); }
    public function parentInteraction() { return $this->belongsTo(self::class, 'parent_interaction_id'); }
    public function relatedInteractions() { return $this->hasMany(self::class, 'parent_interaction_id'); }
    public function application() { return $this->belongsTo(Application::class, 'app_id'); }
    public function establishment() { return $this->belongsTo(Establishment::class, 'entity_id')->where('entity_type', 'Establishment'); }
    public function employer() { return $this->belongsTo(Employer::class, 'entity_id')->where('entity_type', 'Employer'); }
    public function item() { return $this->belongsTo(Item::class, 'entity_id')->where('entity_type', 'Item'); }
    public function order() { return $this->belongsTo(Order::class, 'entity_id')->where('entity_type', 'Order'); }
    public function entity(): MorphTo { return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id'); }

    public function scopeViews($query) { return $query->where('interaction_type', 'view'); }
    public function scopeLikes($query) { return $query->where('interaction_type', 'like'); }
    public function scopeComments($query) { return $query->where('interaction_type', 'comment'); }
    public function scopeShares($query) { return $query->where('interaction_type', 'share'); }
    public function scopeRatings($query) { return $query->where('interaction_type', 'rating'); }
    public function scopeFromEntity($query, $type, $id) { return $query->where('entity_type', $type)->where('entity_id', $id); }

    public static function totalViewsForEntity($entityType, $entityId)
    {
        return static::where('entity_type', $entityType)->where('entity_id', $entityId)->views()->count();
    }

    public static function uniqueViewersForEntity($entityType, $entityId)
    {
        return static::where('entity_type', $entityType)->where('entity_id', $entityId)->views()->distinct('user_id')->count('user_id');
    }

    public static function mostActiveUserForEntity($entityType, $entityId)
    {
        return static::where('entity_type', $entityType)->where('entity_id', $entityId)
            ->selectRaw('user_id, COUNT(*) as total')->groupBy('user_id')->orderByDesc('total')
            ->with('user:id,first_name,last_name,user_name,avatar,email')->first();
    }

    public static function analyticsForEntity($entityType, $entityId)
    {
        return [
            'total_views' => self::totalViewsForEntity($entityType, $entityId),
            'unique_users' => self::uniqueViewersForEntity($entityType, $entityId),
            'most_active_user' => self::mostActiveUserForEntity($entityType, $entityId),
        ];
    }

    public function getSummaryAttribute()
    {
        return [
            'id' => $this->id,
            'type' => $this->interaction_type,
            'application' => $this->application ? [
                'id' => $this->application->id,
                'name' => $this->application->name,
                'slug' => $this->application->slug,
                'url' => $this->application->url,
            ] : null,
            'entity' => ['type' => $this->entity_type, 'id' => $this->entity_id, 'name' => $this->name],
            'user' => [
                'id' => $this->user?->id,
                'name' => trim(($this->user?->first_name ?? '') . ' ' . ($this->user?->last_name ?? '')),
                'email' => $this->user?->email,
                'user_name' => $this->user?->user_name,
                'avatar' => $this->user?->avatar,
            ],
            'route' => $this->route,
            'method' => $this->method,
            'comment' => $this->comment,
            'content' => $this->content,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public static function registerLogin($user, $data = [])
    {
        if (! $user) return null;
        return static::register('login', $user, $user, $data, 'Login do usuário');
    }

    public static function registerView($entity, $user = null, $extra = [])
    {
        $ip = request()->ip();
        $userId = $user?->id;
        $exists = static::where('entity_type', class_basename($entity))
            ->where('entity_id', $entity->id)
            ->where('interaction_type', 'view')
            ->where(function ($q) use ($userId, $ip) {
                if ($userId) $q->where('user_id', $userId);
                if ($ip) $q->orWhereJsonContains('content->ip', $ip);
            })
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($exists) return null;
        return static::register('view', $entity, $user, $extra, $entity->name ?? $entity->title ?? 'Visualização');
    }

    public static function registerLike($entity, $user = null)
    {
        return static::register('like', $entity, $user, [], $entity->name ?? $entity->title ?? 'Like');
    }

    public static function registerComment($entity, $user, $commentText)
    {
        $interaction = static::register('comment', $entity, $user, [], $entity->name ?? $entity->title ?? 'Comentário');
        if ($interaction) $interaction->update(['comment' => $commentText]);
        return $interaction;
    }

    public static function tooManyRecentLogins($userId)
    {
        return static::where('user_id', $userId)->whereIn('interaction_type', ['login', 'login_google'])
            ->where('created_at', '>=', now()->subSeconds(10))->exists();
    }

    public static function registerLoginAuto($user)
    {
        return static::registerLogin($user, [
            'latitude' => request('latitude'),
            'longitude' => request('longitude'),
            'city' => request('city'),
            'uf' => request('uf'),
        ]);
    }

    public static function register($type, $entity, $user = null, $content = [], ?string $name = null)
    {
        $app = app(ApplicationContextService::class)->resolve(request(), $entity, $content);

        return static::create([
            'interaction_type' => $type,
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->id,
            'user_id' => $user?->id,
            'app_id' => $app?->id,
            'name' => $name ?: ucfirst(str_replace('_', ' ', $type)),
            'content' => $content,
        ]);
    }

    public static function registerUpdate($entity, $user, array $changes = [], array $extra = [])
    {
        if (! $entity || ! $user) return null;
        return static::register('update', $entity, $user, array_merge(['changes' => $changes], $extra), 'Atualização de ' . class_basename($entity));
    }

    private static function sessionKey(?string $agent, ?string $ip): string
    {
        return substr(hash('sha256', (string) $ip . '|' . (string) $agent), 0, 40);
    }
}
