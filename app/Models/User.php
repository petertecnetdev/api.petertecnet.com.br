<?php

namespace App\Models;

use App\Models\Traits\HasFiles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, HasFiles, Notifiable;

    protected static function booted(): void
    {
        static::saved(function (User $user): void {
            $adminEmail = strtolower((string) config('peter.admin_email'));

            if ($adminEmail && strtolower((string) $user->email) === $adminEmail) {
                $adminProfileId = Profile::query()->where('name', 'Administrador')->value('id');

                if ($adminProfileId && (int) $user->profile_id !== (int) $adminProfileId) {
                    $user->forceFill(['profile_id' => $adminProfileId])->saveQuietly();
                }
            }
        });
    }

    protected $fillable = [
        'user_name', 'first_name', 'last_name', 'email', 'verification_code', 'password',
        'reset_password_code', 'reset_password_expires_at', 'remember_token', 'profile_id',
        'cpf', 'google_id', 'address', 'phone', 'city', 'uf', 'postal_code', 'birthdate',
        'gender', 'marital_status', 'occupation', 'about', 'favorite_artist', 'favorite_genre',
        'payment_method', 'newsletter_subscription', 'ticket_purchases', 'account_balance',
        'is_producer', 'is_participant', 'is_promoter', 'is_partner', 'is_ticket_seller',
        'extra_info', 'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'verification_code',
        'reset_password_code',
        'reset_password_expires_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'reset_password_expires_at' => 'datetime',
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

    public static function geoFromIp($ip): array
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['city' => null, 'uf' => null];
        }

        try {
            $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,message,city,region';
            $context = stream_context_create([
                'http' => [
                    'timeout' => 2,
                    'ignore_errors' => true,
                ],
            ]);
            $response = @file_get_contents($url, false, $context);

            if (! $response) {
                return ['city' => null, 'uf' => null];
            }

            $geo = json_decode($response, true);

            if (is_array($geo) && ($geo['status'] ?? null) === 'success') {
                return [
                    'city' => $geo['city'] ?? null,
                    'uf' => $geo['region'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return ['city' => null, 'uf' => null];
    }

    public static function credentials($username, $password): array
    {
        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            return ['email' => strtolower(trim($username)), 'password' => $password];
        }

        return [
            'cpf' => preg_replace('/[^0-9]/', '', (string) $username),
            'password' => $password,
        ];
    }

    public function files()
    {
        return $this->hasMany(File::class, 'entity_id')->where('entity_name', 'user');
    }
}
