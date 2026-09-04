<?php

namespace App\Domain\Locations\Http\Controllers;

use App\Domain\Locations\Services\LocationLookupService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function __construct(private readonly LocationLookupService $locations) {}

    public function states()
    {
        return response()->json(['states' => $this->locations->states()]);
    }

    public function cities(Request $request)
    {
        $data = $request->validate([
            'uf' => 'nullable|string|size:2',
            'q' => 'nullable|string|min:2|max:120',
        ]);

        return response()->json([
            'cities' => $this->locations->cities($data['uf'] ?? null, $data['q'] ?? null),
        ]);
    }

    public function cep(string $cep)
    {
        $address = $this->locations->cep($cep);
        if (! $address) {
            return response()->json([
                'message' => 'Não foi possível localizar este CEP agora. Você pode preencher o endereço manualmente.',
            ], 404);
        }

        return response()->json(['address' => $address]);
    }

    public function places(Request $request)
    {
        $data = $request->validate([
            'q' => 'required|string|min:2|max:160',
            'session_token' => ['nullable','string','max:120','regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        return response()->json([
            'places' => $this->locations->autocompletePlaces($data['q'], $data['session_token'] ?? null),
        ]);
    }

    public function place(Request $request, string $placeId)
    {
        $data = $request->validate([
            'session_token' => ['nullable','string','max:120','regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        return response()->json([
            'place' => $this->locations->place($placeId, $data['session_token'] ?? null),
        ]);
    }
}
