<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use App\Models\Traits\HasFiles;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, HasFiles, Notifiable;

    protected $fillable = [
        'user_name',
        'first_name',
        'last_name',
        'email',
        'verification_code',
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
        'email_verified_at',
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

    // ============================================================
    // AUTENTICAÇÃO JWT
    // ============================================================

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    // ============================================================
    // RELAÇÕES PADRÃO
    // ============================================================

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

    // ============================================================
    // INTERAÇÕES (VIEW SYSTEM)
    // ============================================================

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
        return $this->views()
            ->latest()
            ->with('entity')
            ->limit(10);
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

    // ============================================================
    // PERMISSÕES E PERFIL
    // ============================================================

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
    public function updateAddress($city, $uf)
    {
        if (!$city && !$uf) {
            return false;
        }

        return $this->update([
            'city' => $city ?: $this->city,
            'uf' => $uf ?: $this->uf,
        ]);
    }

    public static function geoFromIp($ip)
    {
        try {
            $url = "http://ip-api.com/json/{$ip}?fields=status,message,city,region";
            $geo = json_decode(file_get_contents($url), true);

            if ($geo['status'] === 'success') {
                return [
                    'city' => $geo['city'] ?? null,
                    'uf' => $geo['region'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
        }

        return ['city' => null, 'uf' => null];
    }
    public static function credentials($username, $password)
    {
        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $username, 'password' => $password];
        }

        return ['cpf' => preg_replace('/[^0-9]/', '', $username), 'password' => $password];
    }

    public function files()
    {
        return $this->hasMany(File::class, 'created_by');
    }


}
