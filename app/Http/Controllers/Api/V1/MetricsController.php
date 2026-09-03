<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Database\Eloquent\Builder;
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
            ->forApplication($this->context->id())
            ->whereKey($establishment)
            ->where('user_id', $request->user()->id)
            ->where('is_cancelled', false)
            ->firstOrFail();

        $metrics = $model->getMetricsAttribute();
        $restrictedAttempts = $model->interactions()
            ->where('interaction_type', 'restricted_access');

        $metrics['restricted_access_attempts'] = (clone $restrictedAttempts)->count();
        $metrics['restricted_access_attempts_30d'] = (clone $restrictedAttempts)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
        $metrics['restricted_access_last_at'] = (clone $restrictedAttempts)
            ->max('created_at');

        return response()->json([
            'success' => true,
            'data' => $metrics,
        ]);
    }

    public function item(Request $request, int $item): JsonResponse
    {
        $applicationId = $this->context->id();
        $userId = $request->user()->id;

        $model = Item::query()
            ->whereKey($item)
            ->where('entity_name', 'establishment')
            ->whereHas('establishment', function (Builder $query) use ($applicationId, $userId) {
                $query->forApplication($applicationId)
                    ->where('user_id', $userId)
                    ->where('is_cancelled', false);
            })
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $model->getMetricsAttribute(),
        ]);
    }
}
