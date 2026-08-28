<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class Profile extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'permissions'];

    protected $casts = [
        'permissions' => 'array',
    ];

    public $timestamps = false;

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function getPermissionNamesAttribute()
    {
        $permissionsArray = is_array($this->permissions) ? $this->permissions : [];

        if ($permissionsArray === []) {
            return '<i>Nenhuma Permissão Atribuída</i>';
        }

        $names = [];
        $permissions = Config::get('permissions', []);

        foreach ($permissionsArray as $key) {
            if (isset($permissions[$key]['name'])) {
                $names[] = $permissions[$key]['name'];
            }
        }

        return implode(' | ', $names);
    }
}
