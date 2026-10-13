<?php

declare(strict_types=1);

namespace App\Models\Independence;

use Illuminate\Database\Eloquent\Model;

/** Claim submissions cannot mass-assign privileged review or ownership fields. */
final class OwnershipClaim extends Model
{
    protected $table = 'independence_ownership_claims';

    protected $guarded = ['*'];

    protected $hidden = ['contact_token_hash', 'active_claim_key'];

    protected $casts = [
        'consented_at' => 'immutable_datetime',
        'contact_token_expires_at' => 'immutable_datetime',
        'contact_verified_at' => 'immutable_datetime',
        'reviewed_at' => 'immutable_datetime',
    ];
}
