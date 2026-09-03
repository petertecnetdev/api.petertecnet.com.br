<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    private const CONVERSION_EVENTS = [
        'frontend_company_profile_view',
        'frontend_catalog_open',
        'frontend_contact_click',
        'frontend_share',
        'frontend_external_link_click',
        'frontend_qr_action',
        'frontend_item_open',
    ];

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function establishment(Request $request, int $establishment): JsonResponse
    {
        $model = Establishment::query()
            ->whereKey($establishment)
            ->forApplication($this->context->id())
            ->where('user_id', $request->user()->id)
            ->where('is_cancelled', false)
            ->firstOrFail();

        $events = Interaction::query()
            ->where('app_id', $this->context->id())
            ->where('entity_type', 'Establishment')
            ->where('entity_id', $model->id)
            ->whereIn('interaction_type', self::CONVERSION_EVENTS)
            ->selectRaw('interaction_type, COUNT(*) as total')
            ->groupBy('interaction_type')
            ->pluck('total', 'interaction_type');

        $conversion = [];
        foreach (self::CONVERSION_EVENTS as $event) {
            $conversion[substr($event, strlen('frontend_'))] = (int) ($events[$event] ?? 0);
        }

        $profileViews = max($conversion['company_profile_view'], 1);
        $conversion['contact_rate'] = round(($conversion['contact_click'] / $profileViews) * 100, 2);
        $conversion['catalog_rate'] = round(($conversion['catalog_open'] / $profileViews) * 100, 2);
        $conversion['share_rate'] = round(($conversion['share'] / $profileViews) * 100, 2);

        return response()->json([
            'success' => true,
            'data' => array_merge($model->getMetricsAttribute(), [
                'conversion' => $conversion,
            ]),
        ]);
    }

    public function item(Request $request, int $item): JsonResponse
    {
        $model = Item::query()
            ->whereKey($item)
            ->where('entity_name', 'establishment')
            ->whereIn('entity_id', function ($query) use ($request) {
                $query->select('id')
                    ->from('establishments')
                    ->where('user_id', $request->user()->id)
                    ->where('is_cancelled', false)
                    ->where(function ($scope) {
                        $scope->where('app_id', $this->context->id())
                            ->orWhereExists(function ($pivot) {
                                $pivot->selectRaw('1')
                                    ->from('application_establishment')
                                    ->whereColumn('application_establishment.establishment_id', 'establishments.id')
                                    ->where('application_establishment.application_id', $this->context->id());
                            });
                    });
            })
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $model->getMetricsAttribute(),
        ]);
    }
}
