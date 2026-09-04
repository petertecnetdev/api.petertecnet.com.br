<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountIdentityProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'nationality', 'birthplace', 'identity_document_type',
        'identity_document_number', 'identity_document_issuer', 'parent_1', 'parent_2',
    ];

    protected $casts = [
        'nationality' => 'encrypted',
        'birthplace' => 'encrypted',
        'identity_document_type' => 'encrypted',
        'identity_document_number' => 'encrypted',
        'identity_document_issuer' => 'encrypted',
        'parent_1' => 'encrypted',
        'parent_2' => 'encrypted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
