<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'application_key',
        'provider',
        'plan_key',
        'status',
        'amount',
        'currency',
        'external_reference',
        'provider_subscription_id',
        'provider_payment_method',
        'checkout_url',
        'trial_ends_at',
        'current_period_ends_at',
        'next_payment_at',
        'cancelled_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'trial_ends_at' => 'datetime',
        'current_period_ends_at' => 'datetime',
        'next_payment_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payments()
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function hasAccess(): bool
    {
        if (in_array($this->status, ['authorized', 'active', 'trialing'], true)) {
            return true;
        }

        if ($this->status === 'past_due' && $this->current_period_ends_at) {
            return now()->lessThanOrEqualTo($this->current_period_ends_at->copy()->addDays((int) config('subscriptions.grace_days', 3)));
        }

        return false;
    }
}
