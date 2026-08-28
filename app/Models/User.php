<?php

namespace App\Models;

use App\Models\Traits\HasFiles;
use App\Services\LocationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, HasFiles, Notifiable;

    protected $fillable = [
        'user_name', 'first_name', 'last_name', 'email', 'verification_code', 'verification_code_expires_at', 'password',
        'auth_version', 'reset_password_code', 'reset_password_expires_at', 'remember_token', 'profile_id',
        'cpf', 'google_id', 'avatar', 'address', 'phone', 'city', 'uf', 'postal_code', 'birthdate',
        'gender', 'marital_status', 'occupation', 'about', 'favorite_artist', 'favorite_genre',
        'payment_method', 'newsletter_subscription', 'ticket_purchases', 'account_balance',
        'is_producer', 'is_participant', 'is_promoter', 'is_barber', 'is_barbershoper',
        'is_partner', 'is_ticket_seller', 'extra_info', 'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'verification_code',
        'verification_code_expires_at',
        'reset_password_code',
        'reset_password_expires_at',
        'auth_version',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'verification_code_expires_at' => 'datetime',
        'reset_password_expires_at' => 'datetime',
        'birthdate' => 'date',
        'extra_info' => 'array',
        'newsletter_subscription' => 'boolean',
        'is_producer' => 'boolean',
        'is_participant' => 'boolean',
        'is_promoter' => 'boolean',
        'is_barber' => 'boolean',
        'is_barbershoper' => 'boolean',
        'is_partner' => 'boolean',
        'is_ticket_seller' => 'boolean',
        'account_balance' => 'decimal:2',
        'ticket_purchases' => 'integer',
        'auth_version' => 'integer',
    ];

    protected static function booted()
    {
        static::updating(function (User $user) {
            if ($user->isDirty('password') && ! $user->isDirty('auth_version')) {
                $user->auth_version = ((int) $user->getOriginal('auth_version')) + 1;
            }
        });
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return ['ver' => (int) ($this->auth_version ?: 1)];
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

    /**
     * Legacy single-employment accessor kept for frontend compatibility.
     * New code must use employments().
     */
    public function employer()
    {
        return $this->hasOne(Employer::class, 'user_id')->latestOfMany();
    }

    public function employments()
    {
        return $this->hasMany(Employer::class, 'user_id');
    }

    public function establishments()
    {
        return $this->hasMany(Establishment::class);
    }

    public function applications()
    {
        return $this->belongsToMany(Application::class, 'application_user')
            ->withPivot(['role', 'status', 'metadata', 'joined_at'])
            ->withTimestamps();
    }

    public function interactions()
    {
        return $this->hasMany(Interaction::class);
    }

    public function views()
    {
        return $this->hasMany(Interaction::class)->where('interaction_type', 'view');
    }

    public function itemViews()
    {
        return $this->views()->where('entity_type', 'Item');
    }

    public function establishmentViews()
    {
        return $this->views()->where('entity_type', 'Establishment');
    }

    public function employerViews()
    {
        return $this->views()->where('entity_type', 'Employer');
    }

    public function totalViewsCount()
    {
        return $this->views()->count();
    }

    public function lastViewedEntities()
    {
        return $this->views()->latest()->with('entity')->limit(10);
    }

    public function mostViewedEntityType()
    {
        return $this->views()
            ->selectRaw('entity_type, COUNT(*) as total')
            ->groupBy('entity_type')
            ->orderByDesc('total')
            ->first();
    }

    public function mostViewedEntities()
    {
        return $this->views()
            ->selectRaw('entity_type, entity_id, COUNT(*) as total')
            ->groupBy('entity_type', 'entity_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get();
    }

    public function hasProfile($profileName): bool
    {
        return $this->profile && $this->profile->name === $profileName;
    }

    public function hasPermission($permissionName): bool
    {
        if (! $this->profile || ! is_array($this->profile->permissions)) {
            return false;
        }

        $aliases = [
            'profile_list' => 'profile_view',
            'profile_show' => 'profile_view',
            'ticket_update' => 'ticket_edit',
            'production_update' => 'production_edit',
        ];

        $canonicalPermission = $aliases[$permissionName] ?? $permissionName;

        return in_array($canonicalPermission, $this->profile->permissions, true)
            || in_array($permissionName, $this->profile->permissions, true);
    }

    public function updateAddress($city, $uf)
    {
        if (! $city && ! $uf) {
            return false;
        }

        return $this->update([
            'city' => $city ?: $this->city,
            'uf' => $uf ?: $this->uf,
        ]);
    }

    /**
     * Backwards-compatible facade for legacy callers. Network I/O now lives
     * in LocationService, where HTTPS, timeout and caching are centralized.
     */
    public static function geoFromIp($ip): array
    {
        return app(LocationService::class)->fromIp($ip);
    }

    public static function credentials($username, $password): array
    {
        $identifier = trim((string) $username);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return ['email' => strtolower($identifier), 'password' => $password];
        }

        $cpf = preg_replace('/[^0-9]/', '', $identifier);
        if (strlen($cpf) === 11) {
            return ['cpf' => $cpf, 'password' => $password];
        }

        return ['user_name' => $identifier, 'password' => $password];
    }

    public function files()
    {
        return $this->hasMany(File::class, 'entity_id')->where('entity_name', 'user');
    }
}
