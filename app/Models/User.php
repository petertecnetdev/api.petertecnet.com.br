<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'user_name',
        'first_name',
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
        'google_id',
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

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'newsletter_subscription' => 'boolean',
        'is_producer' => 'boolean',
        'is_participant' => 'boolean',
        'is_promoter' => 'boolean',
        'is_partner' => 'boolean',
        'is_ticket_seller' => 'boolean',
        'account_balance' => 'decimal:2',
        'ticket_purchases' => 'integer',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

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

    public function events()
    {
        return $this->hasManyThrough(Event::class, Production::class);
    }

    public function employer()
    {
        return $this->hasOne(Employer::class, 'user_id');
    }

    public function establishments()
    {
        return $this->hasMany(Establishment::class);
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
}
