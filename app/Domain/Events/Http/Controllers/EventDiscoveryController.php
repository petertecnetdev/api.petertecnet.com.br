<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventDiscoveryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class EventDiscoveryController extends Controller
{
    public function __construct(private readonly EventDiscoveryService $service) {}

    public function events(Request $request)
    {
        return $this->service->events($request);
    }

    public function publicEvent(Request $request, string $slug)
    {
        return $this->service->publicEvent($request, $slug);
    }

    public function facets(Request $request)
    {
        return $this->service->facets($request);
    }
}
