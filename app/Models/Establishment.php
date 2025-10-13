<?php
// app/Models/Establishment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class Establishment extends Model
{
    protected $fillable = [
        'name', 'fantasy', 'slug', 'cnpj', 'type', 'category',
        'phone', 'email', 'description', 'additional_info',
        'city', 'location', 'cep', 'address',
        'user_id', 'updated_by', 'logo', 'background',
        'is_featured', 'is_published', 'is_approved', 'is_cancelled',
        'website_url', 'facebook_url', 'instagram_url',
        'twitter_url', 'youtube_url', 'segments'
    ];

    protected $casts = [
        'segments'     => 'json',
        'is_featured'  => 'boolean',
        'is_published' => 'boolean',
        'is_approved'  => 'boolean',
        'is_cancelled' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->fantasy ?? $model->name);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function interactions()
    {
        return $this->hasMany(Interaction::class, 'entity_id')
                    ->where('entity_type', 'establishment');
    }

    // Corrige a foreign key e filtra pelo entity_name adequado
    public function items()
    {
        return $this->hasMany(Item::class, 'entity_id')
                    ->where('entity_name', 'establishment');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
public function employers()
{
    return $this->hasMany(Employer::class);
}


    public function getSegmentsnNamesAttribute()
    {
        $segmentsArray = is_string($this->segments)
                       ? json_decode($this->segments, true)
                       : [];

        if (empty($segmentsArray)) {
            return '<i>Nenhum seguimento atribuído</i>';
        }

        $names = [];
        $segmentsConfig = Config::get('segments', []);

        foreach ($segmentsArray as $key) {
            if (isset($segmentsConfig[$key])) {
                $names[] = $segmentsConfig[$key]['name'];
            }
        }

        return implode(' | ', $names);
    }
}
