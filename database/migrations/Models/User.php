<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Notifications\{CustomVerifyEmail, CustomResetPasswordNotification};


class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'avatar',
        'password',
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
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
    public function sendEmailVerificationNotification()
{
    $this->notify(new CustomVerifyEmail);
}
public function productions()
    {
        return $this->hasMany(Production::class);
    }
public function sendPasswordResetNotification($token)
{
    $this->notify(new CustomResetPasswordNotification($token));
}
public function profile()
{
    return $this->belongsTo(Profile::class);
}
public function hasProfile($profileName)
{
    return $this->profile && $this->profile->name === $profileName;
}

public function hasPermission($permissionName)
{
    return $this->profile && in_array($permissionName, $this->profile->permissions);
}

public function items()
{
    return $this->hasMany(Item::class);
}

public function events()
{
    return $this->hasManyThrough(Event::class, Production::class, 'user_id', 'production_id');
}

}
