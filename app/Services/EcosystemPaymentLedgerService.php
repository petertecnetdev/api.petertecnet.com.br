<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EcosystemPaymentLedgerService
{
    public function syncCutinappPayment(int $paymentId): void
    {
        if (!Schema::hasTable('ecosystem_payments') || !Schema::hasTable('cutinapp_payments')) {
            return;
        }

        $row = DB::table('cutinapp_payments as p')
            ->join('cutinapp_orders as o', 'o.id', '=', 'p.order_id')
            ->leftJoin('applications as a', function ($join) {
                $join->on('a.slug', '=', DB::raw("'cutinapp'"));
            })
            ->where('p.id', $paymentId)
            ->select([
                'p.id as payment_id',
                'p.provider',
                'p.provider_payment_id',
                'p.method',
                'p.status',
                'p.amount',
                'p.provider_fee',
                'p.paid_at',
                'p.refunded_at',
                'p.failed_at',
                'p.created_at',
                'p.updated_at',
                'o.id as order_id',
                'o.public_id as order_public_id',
                'o.user_id',
                'o.production_id',
                'o.currency',
                'o.platform_fee',
                'o.producer_net',
                'o.event_id',
                'o.status as order_status',
                'o.metadata as order_metadata',
                'a.id as app_id',
            ])
            ->first();

        if (!$row) return;

        $metadata = [
            'cutinapp_payment_id' => (int) $row->payment_id,
            'cutinapp_order_id' => (int) $row->order_id,
            'order_public_id' => $row->order_public_id,
            'event_id' => $row->event_id ? (int) $row->event_id : null,
            'order_status' => $row->order_status,
            'settlement_mode' => data_get(json_decode((string) $row->order_metadata, true), 'settlement_mode'),
        ];

        $status = (string) $row->status;
        if ($status === 'approved') $status = 'paid';

        $existing = DB::table('ecosystem_payments')
            ->where('app_slug', 'cutinapp')
            ->where('source_type', 'cutinapp_payment')
            ->where('source_reference', (string) $row->payment_id)
            ->first();

        $payload = [
            'app_id' => $row->app_id,
            'app_slug' => 'cutinapp',
            'provider' => $row->provider ?: 'mercadopago',
            'provider_payment_id' => $row->provider_payment_id ?: null,
            'source_type' => 'cutinapp_payment',
            'source_reference' => (string) $row->payment_id,
            'source_id' => (int) $row->payment_id,
            'user_id' => $row->user_id,
            'production_id' => $row->production_id,
            'establishment_id' => null,
            'currency' => $row->currency ?: 'BRL',
            'method' => $row->method,
            'status' => $status,
            'gross_amount' => (float) $row->amount,
            'platform_fee' => (float) $row->platform_fee,
            'provider_fee' => (float) $row->provider_fee,
            'seller_net' => (float) $row->producer_net,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'paid_at' => $row->paid_at,
            'refunded_at' => $row->refunded_at,
            'failed_at' => $row->failed_at,
            'updated_at' => $row->updated_at ?: now(),
        ];

        if ($existing) {
            DB::table('ecosystem_payments')->where('id', $existing->id)->update($payload);
            return;
        }

        DB::table('ecosystem_payments')->insert(array_merge($payload, [
            'public_id' => (string) Str::uuid(),
            'created_at' => $row->created_at ?: now(),
        ]));
    }

    public function backfillCutinapp(): int
    {
        if (!Schema::hasTable('cutinapp_payments')) return 0;

        $count = 0;
        DB::table('cutinapp_payments')->orderBy('id')->pluck('id')->each(function ($id) use (&$count) {
            $this->syncCutinappPayment((int) $id);
            $count++;
        });

        return $count;
    }
}
