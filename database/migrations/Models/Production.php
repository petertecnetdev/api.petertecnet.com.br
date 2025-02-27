<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class Production extends Model
{
    protected $fillable = [
        'name', 'type', 'establishment_type', 'description', 'city', 'location',
        'cep', 'address', 'user_id', 'is_featured', 'is_published', 'is_approved', 'is_cancelled',
        'additional_info', 'facebook_url', 'twitter_url', 'instagram_url', 'youtube_url',
        'other_information', 'ticket_price_min', 'ticket_price_max', 'total_tickets_sold',
        'total_tickets_available', 'logo', 'segments',
    ];

    protected $casts = [
        'segments' => 'json',
    ];

    // Relação com o usuário responsável pela produção
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relação com as interações da produção
    public function interactions()
    {
        return $this->hasMany(Interaction::class, 'entity_id')->where('entity_type', 'production');
    }
    public function itemMenus()
    {
        return $this->hasMany(ItemMenu::class, 'entity_id')->where('entity_name', 'production');
    }

    // Método para obter os nomes dos segmentos atribuídos à produção
    public function getSegmentsnNamesAttribute()
    {
        $segmentsArray = is_string($this->segments) ? json_decode($this->segments, true) : [];

        if (is_null($segmentsArray) || empty($segmentsArray) || count($segmentsArray) <= 0) {
            return '<i>Nenhum seguimento atribuido</i>';
        }

        $names = [];
        $segments = Config::get('segments'); // Certifique-se de ter definido seu arquivo de configuração

        foreach ($segmentsArray as $key) {
            if (isset($segments[$key])) {
                $names[] = $segments[$key]['name'];
            }
        }

        return implode(" | ", $names);
    }
    public function events()
    {
        return $this->hasMany(Event::class)->orderBy('start_date', 'desc');
    }
    public function menus()
    {
        return $this->hasMany(Menu::class);
    }
    // Outros métodos e lógica associados à model Production
}
