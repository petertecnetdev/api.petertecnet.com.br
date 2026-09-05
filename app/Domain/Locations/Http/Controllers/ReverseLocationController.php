<?php

namespace App\Domain\Locations\Http\Controllers;

use App\Domain\Locations\Services\ReverseGeocodingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class ReverseLocationController extends Controller
{
    public function show(Request $request, ReverseGeocodingService $geocoding)
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        return response()->json([
            'location' => $geocoding->resolve((float) $data['lat'], (float) $data['lng']),
        ]);
    }
}
