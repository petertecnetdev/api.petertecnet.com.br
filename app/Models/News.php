<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class News extends Model
{
    use HasFactory;

    /**
     * Os atributos que são atribuíveis em massa.
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'content',
        'image',
        'published_at',
        'user_id',
    ];

    /**
     * O campo de data a ser tratado como uma instância do Carbon.
     *
     * @var array
     */
    protected $dates = [
        'published_at',
    ];

    /**
     * Relacionamento: A notícia pertence a um usuário (autor).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function comments()
    {
        return $this->hasMany(Interaction::class, 'entity_id')->where('entity_type', 'news');
    }
    /**
     * Escopo para buscar notícias publicadas.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }
}
