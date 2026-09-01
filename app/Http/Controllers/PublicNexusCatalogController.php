<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Item;
use Illuminate\Http\JsonResponse;

class PublicNexusCatalogController extends Controller
{
    public function byCnpj(string $cnpj): JsonResponse
    {
        $normalizedCnpj = preg_replace('/\D+/', '', $cnpj);

        if (strlen($normalizedCnpj) !== 14) {
            return response()->json([
                'success' => false,
                'message' => 'CNPJ inválido.',
            ], 422);
        }

        $nexusApp = Application::query()
            ->where('slug', 'nexus')
            ->first(['id', 'name', 'slug', 'url', 'logo']);

        if (!$nexusApp) {
            return response()->json([
                'success' => false,
                'message' => 'Aplicativo Nexus não está cadastrado no ecossistema.',
            ], 404);
        }

        $establishment = Establishment::query()
            ->where('app_id', $nexusApp->id)
            ->where('is_cancelled', false)
            ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', ''), ' ', '') = ?", [$normalizedCnpj])
            ->with(['files' => function ($query) {
                $query->orderBy('position');
            }])
            ->latest('updated_at')
            ->first();

        if (!$establishment) {
            return response()->json([
                'success' => false,
                'message' => 'Empresa não encontrada na Nexus para o CNPJ informado.',
                'cnpj' => $normalizedCnpj,
            ], 404);
        }

        $items = Item::query()
            ->where('app_id', $nexusApp->id)
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->where('status', true)
            ->with(['files' => function ($query) {
                $query->orderBy('position');
            }])
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->get()
            ->map(function (Item $item) {
                return [
                    'id' => $item->id,
                    'slug' => $item->slug,
                    'name' => $item->name,
                    'type' => $item->type,
                    'category' => $item->category,
                    'subcategory' => $item->subcategory,
                    'description' => $item->description,
                    'price' => $item->price,
                    'is_featured' => (bool) $item->is_featured,
                    'image_url' => $item->image_url,
                    'files' => $item->files,
                    'updated_at' => $item->updated_at,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'source' => 'nexus',
            'application' => $nexusApp,
            'establishment' => [
                'id' => $establishment->id,
                'name' => $establishment->name,
                'fantasy' => $establishment->fantasy,
                'slug' => $establishment->slug,
                'cnpj' => $establishment->cnpj,
                'description' => $establishment->description,
                'website_url' => $establishment->website_url,
                'instagram_url' => $establishment->instagram_url,
                'files' => $establishment->files,
            ],
            'items' => $items,
        ]);
    }
}
