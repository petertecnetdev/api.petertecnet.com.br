<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class Establishment extends Model
{
    protected $fillable = [
        'name',
        'fantasy',
        'slug',
        'cnpj',
        'type',
        'category',
        'phone',
        'email',
        'description',
        'additional_info',
        'city',
        'location',
        'cep',
        'address',
        'user_id',
        'updated_by',
        'logo',
        'background',
        'is_featured',
        'is_published',
        'is_approved',
        'is_cancelled',
        'website_url',
        'facebook_url',
        'instagram_url',
        'twitter_url',
        'youtube_url',
        'segments'
    ];

    protected $casts = [
        'segments' => 'json',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'is_approved' => 'boolean',
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
        return $this->hasMany(Interaction::class, 'entity_id')->where('entity_type', 'establishment');
    }

    public function getSegmentsnNamesAttribute()
    {
        $segmentsArray = is_string($this->segments) ? json_decode($this->segments, true) : [];

        if (is_null($segmentsArray) || empty($segmentsArray) || count($segmentsArray) <= 0) {
            return '<i>Nenhum seguimento atribuído</i>';
        }

        $names = [];
        $segments = Config::get('segments');

        foreach ($segmentsArray as $key) {
            if (isset($segments[$key])) {
                $names[] = $segments[$key]['name'];
            }
        }

        return implode(" | ", $names);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
