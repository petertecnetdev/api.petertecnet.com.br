<?php

namespace App\Domain\Creative\Http\Controllers;

use App\Domain\Creative\Services\CatalogImageService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use RuntimeException;

final class CatalogImageController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CatalogImageService $catalogImages,
    ) {}

    public function image(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|min:2|max:255',
            'description' => 'nullable|string|max:3000',
            'item_type' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:255',
            'subcategory' => 'nullable|string|max:255',
            'brand' => 'nullable|string|max:255',
            'catalog_name' => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->catalogImages->generate(
                $data,
                (int) $request->user()->id,
                $this->context->id(),
            );
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getMessage(),
                'fallback_available' => true,
            ], 503);
        }

        return response()->json([
            'image' => [
                'data_uri' => 'data:'.$result['mime_type'].';base64,'.$result['image'],
                'mime_type' => $result['mime_type'],
                'provider' => $result['provider'],
                'model' => $result['model'],
            ],
            'usage' => [
                'purpose' => 'catalog_item_image',
                'plan' => 'guarded',
            ],
        ]);
    }
}
