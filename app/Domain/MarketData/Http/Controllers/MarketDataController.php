<?php

namespace App\Domain\MarketData\Http\Controllers;

use App\Domain\MarketData\Services\AirdropOpportunityService;
use App\Domain\MarketData\Services\CoinMarketCapScannerService;
use App\Domain\MarketData\Services\MarketDataService;
use App\Domain\MarketData\Services\MarketPortfolioService;
use App\Domain\MarketData\Services\MarketSignalService;
use App\Http\Controllers\Controller;
use App\Services\ApplicationRuntimeControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

final class MarketDataController extends Controller
{
    public function __construct(
        private readonly MarketDataService $marketData,
        private readonly MarketPortfolioService $portfolio,
        private readonly CoinMarketCapScannerService $scanner,
        private readonly MarketSignalService $signals,
        private readonly AirdropOpportunityService $airdrops,
        private readonly ApplicationRuntimeControlService $runtime,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        return $this->marketResponse($request, fn () => $this->marketData->overview());
    }

    public function scanner(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        return $this->marketResponse($request, fn () => $this->scanner->scan((int) ($validated['limit'] ?? 50)));
    }

    public function signals(Request $request): JsonResponse
    {
        return $this->marketResponse($request, fn () => $this->signals->current());
    }

    public function opportunityReport(Request $request, string $asset): JsonResponse
    {
        return $this->marketResponse($request, fn () => $this->signals->opportunityReport($asset));
    }

    public function realtimeConfig(Request $request): JsonResponse
    {
        if (! $this->runtime->allows($request->route('application'), 'realtime_enabled')) {
            return response()->json([
                'enabled' => false,
                'runtime_suspended' => true,
                'mode' => $this->runtime->settings($request->route('application'))['mode'],
                'message' => 'Realtime temporariamente suspenso pelo Admin Center.',
            ]);
        }

        $app = (array) config('reverb.apps.apps.0', []);
        $options = (array) ($app['options'] ?? []);
        $key = (string) ($app['key'] ?? '');
        $host = (string) ($options['host'] ?? config('reverb.servers.reverb.hostname') ?? '');
        $scheme = (string) ($options['scheme'] ?? 'https');
        $port = (int) ($options['port'] ?? ($scheme === 'https' ? 443 : 80));

        return response()->json([
            'enabled' => $key !== '' && $host !== '',
            'key' => $key,
            'host' => $host,
            'scheme' => $scheme,
            'port' => $port,
            'channel' => 'App.Models.User.'.(int) $request->user()->id,
            'event' => 'app.notification.created',
            'auth_endpoint' => rtrim((string) config('app.url'), '/').'/broadcasting/auth',
        ]);
    }

    public function candles(Request $request, string $asset): JsonResponse
    {
        $validated = $request->validate([
            'quote' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9]+$/'],
            'interval' => ['nullable', 'in:1m,3m,5m,15m,30m,1h,2h,4h,6h,8h,12h,1d,3d,1w,1M'],
            'limit' => ['nullable', 'integer', 'min:20', 'max:500'],
        ]);

        return $this->marketResponse($request, fn () => $this->marketData->candles(
            $asset,
            (string) ($validated['quote'] ?? 'USDT'),
            (string) ($validated['interval'] ?? '1h'),
            (int) ($validated['limit'] ?? 200),
        ));
    }

    public function analyze(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000000'],
            'risk_profile' => ['nullable', 'in:conservador,moderado,agressivo'],
            'horizon' => ['nullable', 'in:intraday,swing,position'],
            'asset_ids' => ['nullable', 'array', 'max:20'],
            'asset_ids.*' => ['string', 'max:80'],
        ]);

        return $this->marketResponse($request, fn () => $this->portfolio->analyze((int) $request->user()->id, $data), 200, 'ai_enabled');
    }

    public function portfolio(Request $request): JsonResponse
    {
        return $this->marketResponse($request, fn () => $this->portfolio->portfolio((int) $request->user()->id));
    }

    public function addPosition(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['required', 'string', 'max:80'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:1000000000000'],
            'average_price' => ['required', 'numeric', 'gt:0', 'max:1000000000000'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->marketResponse($request, fn () => $this->portfolio->addPosition((int) $request->user()->id, $data), 201);
    }

    public function removePosition(Request $request, int $position): JsonResponse
    {
        $this->portfolio->removePosition((int) $request->user()->id, $position);

        return response()->json(['success' => true, 'message' => 'Posição removida.']);
    }

    public function riskProfile(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->portfolio->riskProfile((int) $request->user()->id),
        ]);
    }

    public function saveRiskProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'risk_profile' => ['required', 'in:conservador,moderado,agressivo'],
            'max_asset_exposure' => ['required', 'numeric', 'between:5,100'],
            'max_scenario_loss' => ['required', 'numeric', 'between:1,90'],
            'min_liquidity_reserve' => ['nullable', 'numeric', 'between:0,95'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->portfolio->saveRiskProfile((int) $request->user()->id, $data),
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        return $this->marketResponse($request, fn () => $this->portfolio->alerts((int) $request->user()->id));
    }

    public function addAlert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['required', 'string', 'max:80'],
            'metric' => ['required', 'in:price,change_24h,change_7d,score,confidence'],
            'operator' => ['required', 'in:>,>=,<,<='],
            'threshold' => ['required', 'numeric', 'between:-1000000000000,1000000000000'],
            'active' => ['nullable', 'boolean'],
        ]);

        return $this->marketResponse($request, fn () => $this->portfolio->addAlert((int) $request->user()->id, $data), 201);
    }

    public function removeAlert(Request $request, int $alert): JsonResponse
    {
        $this->portfolio->removeAlert((int) $request->user()->id, $alert);

        return response()->json(['success' => true, 'message' => 'Alerta removido.']);
    }

    public function simulate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market_move_pct' => ['required', 'numeric', 'between:-90,1000'],
        ]);

        return $this->marketResponse($request, fn () => $this->portfolio->simulate((int) $request->user()->id, $data));
    }

    public function airdrops(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->airdrops->campaigns((int) $request->user()->id),
        ]);
    }

    public function airdrop(Request $request, string $slug): JsonResponse
    {
        return $this->airdropResponse(fn () => $this->airdrops->campaign((int) $request->user()->id, $slug));
    }

    public function airdropPlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'capital_usdt' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'risk_profile' => ['nullable', 'in:conservador,moderado,agressivo'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->airdrops->plan((float) $data['capital_usdt'], (string) ($data['risk_profile'] ?? 'moderado')),
        ]);
    }

    public function completeAirdropTask(Request $request, string $slug, string $task): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:255'],
            'confirmed_by_user' => ['required', 'accepted'],
        ]);

        return $this->airdropResponse(
            fn () => $this->airdrops->completeTask((int) $request->user()->id, $slug, $task, $data['reference'] ?? null)
        );
    }

    public function airdropIntelligence(Request $request): JsonResponse
    {
        $data = $request->validate([
            'capital_usdt' => ['nullable', 'numeric', 'min:1', 'max:10000000'],
            'risk_profile' => ['nullable', 'in:conservador,moderado,agressivo'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->airdrops->intelligence(
                (int) $request->user()->id,
                (float) ($data['capital_usdt'] ?? 280),
                (string) ($data['risk_profile'] ?? 'moderado')
            ),
        ]);
    }

    public function airdropWalletEligibility(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'max:255'],
        ]);

        return $this->airdropResponse(fn () => $this->airdrops->walletEligibility((int) $request->user()->id, $data['address']));
    }

    public function airdropWatchlist(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->airdrops->watchlist((int) $request->user()->id)]);
    }

    public function saveAirdropWatchlist(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate(['saved' => ['required', 'boolean']]);

        return $this->airdropResponse(fn () => $this->airdrops->setWatchlist((int) $request->user()->id, $slug, (bool) $data['saved']));
    }

    public function airdropCalendar(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->airdrops->calendar((int) $request->user()->id)]);
    }

    public function airdropActionPolicy(Request $request, string $action): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->airdrops->actionPolicy($action),
        ]);
    }

    private function airdropResponse(callable $callback): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $callback()]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 'AIRDROP_ACTION_REJECTED',
            ], 422);
        }
    }

    private function marketResponse(Request $request, callable $callback, int $status = 200, string $feature = 'market_scanner_enabled'): JsonResponse
    {
        $application = $request->route('application');
        if (! $this->runtime->allows($application, 'market_scanner_enabled')
            || ($feature !== 'market_scanner_enabled' && ! $this->runtime->allows($application, $feature))) {
            $settings = $this->runtime->settings($application);

            return response()->json([
                'success' => false,
                'message' => 'As análises automáticas desta aplicação estão temporariamente suspensas.',
                'code' => 'APPLICATION_RUNTIME_SUSPENDED',
                'runtime' => [
                    'mode' => $settings['mode'],
                    'processing_enabled' => $settings['processing_enabled'],
                    'market_scanner_enabled' => $settings['market_scanner_enabled'],
                ],
                'request_id' => $request->attributes->get('request_id'),
            ], 503);
        }

        try {
            return response()->json([
                'success' => true,
                'data' => $callback(),
            ], $status);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 'MARKET_DATA_UNAVAILABLE',
                'request_id' => $request->attributes->get('request_id'),
            ], 502);
        }
    }
}
