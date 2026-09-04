<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Domain\Organizations\Services\OrganizationExperienceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class OrganizationExperienceController extends Controller
{
    public function __construct(private readonly OrganizationExperienceService $service) {}

    public function publicExperience(Request $request, string $slug)
    {
        return response()->json($this->service->publicExperience($slug, $request->bearerToken()));
    }

    public function workspace(Request $request, int $id)
    {
        return response()->json($this->service->workspace($id, $request->user()));
    }

    public function updateProfile(Request $request, int $id)
    {
        $data = $request->validate([
            'type' => 'sometimes|nullable|in:fixed,independent',
            'city_id' => 'sometimes|nullable|integer', 'city' => 'sometimes|nullable|string|max:120',
            'uf' => 'sometimes|nullable|string|size:2', 'cep' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:255', 'address_number' => 'sometimes|nullable|string|max:30',
            'neighborhood' => 'sometimes|nullable|string|max:160', 'address_complement' => 'sometimes|nullable|string|max:255',
            'address_reference' => 'sometimes|nullable|string|max:255', 'formatted_address' => 'sometimes|nullable|string|max:700',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90', 'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'place_id' => 'sometimes|nullable|string|max:255', 'google_maps_url' => 'sometimes|nullable|url:http,https|max:2048',
            'location_public' => 'sometimes|boolean',
        ]);

        return response()->json([
            'message' => 'Perfil da produção atualizado.',
            'organization' => $this->service->updateProfile($id, $request->user(), $data),
        ]);
    }
}
