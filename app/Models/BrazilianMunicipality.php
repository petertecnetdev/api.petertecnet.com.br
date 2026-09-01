<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrazilianMunicipality extends Model
{
    protected $primaryKey = 'ibge_code';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = ['ibge_code', 'name', 'normalized_name', 'uf', 'slug'];
}
