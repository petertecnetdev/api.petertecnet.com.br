<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SchedulingCatalogController extends Controller
{
    public function businessCategories(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => array_values(config('scheduling_business_categories', [])),
        ]);
    }

    public function resourceTypes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                ['key' => 'professional', 'label' => 'Profissional'],
                ['key' => 'room', 'label' => 'Sala'],
                ['key' => 'station', 'label' => 'Estação / box'],
                ['key' => 'equipment', 'label' => 'Equipamento'],
                ['key' => 'vehicle', 'label' => 'Veículo'],
                ['key' => 'space', 'label' => 'Espaço'],
                ['key' => 'other', 'label' => 'Outro recurso'],
            ],
        ]);
    }
}
