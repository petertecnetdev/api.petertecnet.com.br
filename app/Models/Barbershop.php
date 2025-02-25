<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Barbershop extends Model
{
    use HasFactory;

    protected $table = 'barbershops';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'description',
        'slug',
        'address',
        'city',
        'state',
        'zipcode',
        'website',
        'location',
        'instagram',
        'latitude',
        'longitude',
        'rating',
        'status',
        'user_id',
        'created_by',
        'updated_by',
        'logo',
        'background_image',
        'terms_of_service',
        'social_media_links',
        'barbers' // Este campo deve armazenar os IDs dos barbeiros
    ];

    protected $casts = [
        'social_media_links' => 'array',
        'barbers' => 'array', // Casting para garantir que seja um array
    ];

    // Relacionamento com o usuário proprietário da barbearia
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function barbers()
    {
        return $this->belongsToMany(Barber::class, 'barber_barbershop')
            ->withTimestamps();
    }  
    
    public function items()
    {
        return $this->hasMany(Item::class, 'entity_id')->where('entity_name', 'barbershop');
    }

}
