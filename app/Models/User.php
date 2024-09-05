<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

use Tymon\JWTAuth\Contracts\JWTSubject;


class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_name',
        'first_name',
        'user_name',
        'last_name',
        'email',
        'verification_code',
        'avatar',
        'password',
        'reset_password_code',
        'reset_password_expires_at', 
        'remember_token',
        'profile_id',
        'cpf',
        'address',
        'phone',
        'city',
        'uf',
        'postal_code',
        'birthdate',
        'gender',
        'marital_status',
        'occupation',
        'about',
        'favorite_artist',
        'favorite_genre',
        'payment_method',
        'newsletter_subscription',
        'ticket_purchases',
        'account_balance',
        'is_producer',
        'is_participant',
        'is_promoter',
        'is_partner',
        'is_ticket_seller',
        'extra_info',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }
    public function profile()
{
    return $this->belongsTo(Profile::class);
}

public function productions()
    {
        return $this->hasMany(Production::class);
    }
public function hasProfile($profileName)
{
    return $this->profile && $this->profile->name === $profileName;
}
public function hasPermission($permissionName)
{
    if (!$this->profile || !is_array($this->profile->permissions)) {
        return false;
    }

    return in_array($permissionName, $this->profile->permissions);
}

public function events()
{
    return $this->hasManyThrough(Event::class, Production::class);
}
}