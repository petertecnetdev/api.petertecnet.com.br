<?php

namespace App\Console\Commands;

use App\Models\CommerceOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MonitorProfitability extends Command
{
    protected $signature = 'profitability:monitor {--hours=6 : Lookback window in hours}';

    protected $description = 'Detect loss-making platform_collection payments without changing billing automatically';

    public function handle(): int
    {
        $hours = min(max((int) $this->option('hours'), 1), 168);
        $since = now()->subHours($hours);
        $groups = [];

        CommerceOrder::query()
            ->where('status', 'paid')
            ->where('created_at', '>=', $since)
            ->whereNotNull('production_id')
            ->select([
                'id',
                'app_id',
                'production_id',
                'payment_method',
                'total',
                'platform_fee',
                'processor_fee',
                'metadata',
            ])
            ->orderBy('id')
            ->chunkById(1000, function (Collection $orders) use (&$groups): void {
                foreach ($orders as $order) {
                    if ((string) data_get($order->metadata, 'settlement_mode') !== 'platform_collection') {
                        continue;
                    }

                    $platformFee = (float) $order->platform_fee;
                    $processorFee = (float) $order->processor_fee;
                    $shortfall = max(0, $processorFee - $platformFee);

                    if ($shortfall <= 0) {
                        continue;
                    }

                    $paymentMethod = trim((string) $order->payment_method) ?: 'unknown';
                    $key = implode(':', [(int) $order->app_id, (int) $order->production_id, $paymentMethod]);

                    if (! isset($groups[$key])) {
                        $groups[$key] = [
                            'app_id' => (int) $order->app_id,
                            'organization_id' => (int) $order->production_id,
                            'payment_method' => $paymentMethod,
                            'loss_making_orders' => 0,
                            'affected_gmv' => 0.0,
                            'contribution_shortfall' => 0.0,
                            'platform_fees' => 0.0,
                            'processor_fees' => 0.0,
                        ];
                    }

                    $groups[$key]['loss_making_orders']++;
                    $groups[$key]['affected_gmv'] += (float) $order->total;
                    $groups[$key]['contribution_shortfall'] += $shortfall;
                    $groups[$key]['platform_fees'] += $platformFee;
                    $groups[$key]['processor_fees'] += $processorFee;
                }
            });

        if ($groups === []) {
            $this->info("No loss-making platform_collection payments found in the last {$hours} hour(s).");

            return self::SUCCESS;
        }

        $actions = collect($groups)
            ->map(function (array $group): array {
                $group['affected_gmv'] = round($group['affected_gmv'], 2);
                $group['contribution_shortfall'] = round($group['contribution_shortfall'], 2);
                $group['platform_fees'] = round($group['platform_fees'], 2);
                $group['processor_fees'] = round($group['processor_fees'], 2);
                $group['fee_rate_gap_to_break_even'] = $group['affected_gmv'] > 0
                    ? round(($group['contribution_shortfall'] / $group['affected_gmv']) * 100, 2)
                    : 0.0;
                $group['recommended_action'] = 'review_platform_collection_fee_or_settlement_mode';

                return $group;
            })
            ->sortByDesc('contribution_shortfall')
            ->values();

        $totalShortfall = round((float) $actions->sum('contribution_shortfall'), 2);
        $affectedGmv = round((float) $actions->sum('affected_gmv'), 2);

        Log::warning('Profitability guard rail detected loss-making platform_collection payments.', [
            'lookback_hours' => $hours,
            'total_contribution_shortfall' => $totalShortfall,
            'affected_gmv' => $affectedGmv,
            'action_count' => $actions->count(),
            'actions' => $actions->take(20)->all(),
        ]);

        $this->warn(sprintf(
            'Detected %d profitability action(s): R$ %.2f contribution shortfall across R$ %.2f affected GMV.',
            $actions->count(),
            $totalShortfall,
            $affectedGmv
        ));

        $this->table(
            ['App', 'Organization', 'Method', 'Orders', 'GMV', 'Shortfall', 'Gap pp'],
            $actions->take(20)->map(fn (array $row): array => [
                $row['app_id'],
                $row['organization_id'],
                $row['payment_method'],
                $row['loss_making_orders'],
                number_format($row['affected_gmv'], 2, '.', ''),
                number_format($row['contribution_shortfall'], 2, '.', ''),
                number_format($row['fee_rate_gap_to_break_even'], 2, '.', ''),
            ])->all()
        );

        return self::SUCCESS;
    }
}
