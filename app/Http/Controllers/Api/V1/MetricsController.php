<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function establishment(Request $request, int $establishment): JsonResponse
    {
        $model = Establishment::query()
            ->whereKey($establishment)
            ->where('app_id', $this->context->id())
            ->where('user_id', $request->user()->id)
            ->where('is_cancelled', false)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $model->getMetricsAttribute(),
        ]);
    }

    public function item(Request $request, int $item): JsonResponse
    {
        $model = Item::query()
            ->whereKey($item)
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->whereIn('entity_id', function ($query) use ($request) {
                $query->select('id')
                    ->from('establishments')
                    ->where('app_id', $this->context->id())
                    ->where('user_id', $request->user()->id)
                    ->where('is_cancelled', false);
            })
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $model->getMetricsAttribute(),
        ]);
    }
}
