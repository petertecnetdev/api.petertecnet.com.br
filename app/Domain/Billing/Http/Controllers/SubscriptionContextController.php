<?php

namespace App\Domain\Billing\Http\Controllers;

use App\Domain\Billing\Services\SubscriptionContextService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class SubscriptionContextController extends Controller
{
    public function __construct(private readonly SubscriptionContextService $subscriptions) {}

    public function plans()
    {
        return response()->json(['data' => $this->subscriptions->plans()]);
    }

    public function current(Request $request)
    {
        return response()->json([
            'data' => $this->subscriptions->currentForUser((int) $request->user()->id),
        ]);
    }
}
