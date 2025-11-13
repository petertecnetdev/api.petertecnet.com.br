<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Interaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'entity_id',
        'entity_type',
        'interaction_type', // ex: view, like, comment, share, favorite, rating
        'comment',
        'name',
        'content',
    ];

    protected $casts = [
        'content' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ===============================
       RELACIONAMENTOS DIRETOS
    ================================ */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class, 'entity_id')
            ->where('entity_type', 'Establishment');
    }

    public function employer()
    {
        return $this->belongsTo(Employer::class, 'entity_id')
            ->where('entity_type', 'Employer');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'entity_id')
            ->where('entity_type', 'Item');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'entity_id')
            ->where('entity_type', 'Order');
    }

    /* Relação polimórfica principal — cobre todas as entidades */
    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }

    /* ===============================
       ESCOPO PARA FILTRAGEM POR TIPO
    ================================ */

    public function scopeViews($query)
    {
        return $query->where('interaction_type', 'view');
    }

    public function scopeLikes($query)
    {
        return $query->where('interaction_type', 'like');
    }

    public function scopeComments($query)
    {
        return $query->where('interaction_type', 'comment');
    }

    public function scopeShares($query)
    {
        return $query->where('interaction_type', 'share');
    }

    public function scopeRatings($query)
    {
        return $query->where('interaction_type', 'rating');
    }

    /* ===============================
       MÉTRICAS E ANALYTICS
    ================================ */

    public static function totalViewsForEntity($entityType, $entityId)
    {
        return static::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('interaction_type', 'view')
            ->count();
    }

    public static function uniqueViewersForEntity($entityType, $entityId)
    {
        return static::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('interaction_type', 'view')
            ->distinct('user_id')
            ->count('user_id');
    }

    public static function mostActiveUserForEntity($entityType, $entityId)
    {
        return static::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('interaction_type', 'view')
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->with('user:id,first_name,last_name,user_name,avatar,email')
            ->first();
    }

    public static function analyticsForEntity($entityType, $entityId)
    {
        return [
            'total_views' => self::totalViewsForEntity($entityType, $entityId),
            'unique_users' => self::uniqueViewersForEntity($entityType, $entityId),
            'most_active_user' => self::mostActiveUserForEntity($entityType, $entityId),
        ];
    }

    /* ===============================
       FORMATAÇÃO E UTILITÁRIOS
    ================================ */

    public function getSummaryAttribute()
    {
        return [
            'id' => $this->id,
            'type' => $this->interaction_type,
            'entity' => [
                'type' => $this->entity_type,
                'id' => $this->entity_id,
                'name' => $this->name,
            ],
            'user' => [
                'id' => $this->user?->id,
                'name' => trim($this->user?->first_name . ' ' . $this->user?->last_name),
                'user_name' => $this->user?->user_name,
                'avatar' => $this->user?->avatar,
            ],
            'comment' => $this->comment,
            'created_at' => $this->created_at?->format('d/m/Y H:i'),
        ];
    }

    public function scopeFromEntity($query, $type, $id)
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }

    /* ===============================
       MÉTODOS PARA REUTILIZAÇÃO GLOBAL
    ================================ */
public static function registerLogin($user, $data = [])
{
    if (!$user) {
        return null;
    }

    return static::create([
        'user_id' => $user->id,
        'entity_id' => $user->id,
        'entity_type' => 'User',
        'interaction_type' => 'login',
        'name' => 'Login do usuário',
        'content' => [
            'ip' => $data['ip'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'city' => $data['city'] ?? null,
            'uf' => $data['uf'] ?? null,
            'user_agent' => $data['user_agent'] ?? request()->userAgent(),
        ],
    ]);
}

   public static function registerView($entity, $user = null, $extra = [])
{
    $ip = request()->ip();
    $userId = $user?->id;
    $entityType = class_basename($entity);
    $entityId = $entity->id;

    // 🔒 Evita duplicar a mesma view em curto período
    $exists = static::where('entity_type', $entityType)
        ->where('entity_id', $entityId)
        ->where('interaction_type', 'view')
        ->where(function ($q) use ($userId, $ip) {
            $q->where('user_id', $userId)
              ->orWhereJsonContains('content->ip', $ip);
        })
        ->where('created_at', '>=', now()->subMinute())
        ->exists();

    if ($exists) {
        return null;
    }

    return static::create([
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'user_id' => $userId,
        'interaction_type' => 'view',
        'name' => $entity->name ?? $entity->title ?? 'Visualização',
        'content' => array_merge([
            'ip' => $ip,
            'user_agent' => request()->userAgent(),
        ], $extra),
    ]);
}

    public static function registerLike($entity, $user = null)
    {
        return static::create([
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->id,
            'user_id' => $user?->id,
            'interaction_type' => 'like',
            'name' => $entity->name ?? $entity->title ?? 'Like',
        ]);
    }

    public static function registerComment($entity, $user, $commentText)
    {
        return static::create([
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->id,
            'user_id' => $user->id,
            'interaction_type' => 'comment',
            'name' => $entity->name ?? $entity->title ?? 'Comentário',
            'comment' => $commentText,
        ]);
    }
    public static function tooManyRecentLogins($userId)
{
    return static::where('user_id', $userId)
        ->where('interaction_type', 'login')
        ->where('created_at', '>=', now()->subSeconds(10))
        ->exists();
}

public static function registerLoginAuto($user)
{
    return static::registerLogin($user, [
        'ip' => request()->ip(),
        'latitude' => request('latitude'),
        'longitude' => request('longitude'),
        'city' => request('city'),
        'uf' => request('uf'),
        'user_agent' => request()->userAgent(),
    ]);
}
public static function register($type, $entity, $user = null, $content = [])
{
    return static::create([
        'interaction_type' => $type,
        'entity_type' => class_basename($entity),
        'entity_id' => $entity->id,
        'user_id' => $user?->id,
        'name' => ucfirst($type),
        'content' => array_merge([
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ], $content),
    ]);
}
public static function registerUpdate($entity, $user, array $changes = [], array $extra = [])
{
    if (!$entity || !$user) {
        return null;
    }

    return static::create([
        'interaction_type' => 'update',
        'entity_type' => class_basename($entity),
        'entity_id' => $entity->id,
        'user_id' => $user->id,
        'name' => 'Atualização de ' . class_basename($entity),

        'content' => array_merge([
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'changes' => $changes
        ], $extra),
    ]);
}

}
