<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class Profile extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $fillable = ['id', 'name', 'permissions'];

    protected $casts = [
        'permissions' => 'json',
    ];

    public $timestamps = false;

    public function getPermissionNamesAttribute()
    {
        $permissionsArray = is_string($this->permissions) ? json_decode($this->permissions, true) : [];

        if (is_null($permissionsArray) || empty($permissionsArray) || count($permissionsArray) <= 0) {
            return '<i>Nenhuma Permissão Atribuída</i>';
        }

        $names = [];
        $permissions = Config::get('permissions'); // Certifique-se de ter definido seu arquivo de configuração

        foreach ($permissionsArray as $key) {
            if (isset($permissions[$key])) {
                $names[] = $permissions[$key]['name'];
            }
        }

        return implode(" | ", $names);
    }
}
