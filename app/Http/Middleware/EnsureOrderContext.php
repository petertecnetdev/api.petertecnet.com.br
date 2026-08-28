<?php

namespace App\Http\Middleware;

use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Item;
use Closure;
use Illuminate\Http\Request;

class EnsureOrderContext
{
    public function handle(Request $request, Closure $next)
    {
        $routeName = (string) optional($request->route())->getName();

        if ($routeName === 'order.listByClient' && ! $request->filled('app_id')) {
            $request->merge(['app_id' => (int) $request->route('app_id')]);
            return $next($request);
        }

        if (! in_array($routeName, ['order.store', 'order.storeDirect'], true)) {
            return $next($request);
        }

        $appId = (int) $request->input('app_id');
        $entityName = (string) $request->input('entity_name', '');
        $entityId = (int) $request->input('entity_id');

        if ($appId <= 0 || $entityName === '' || $entityId <= 0) {
            return $next($request);
        }

        if ($entityName !== 'establishment') {
            abort(422, 'Entidade não suportada para pedidos.');
        }

        $establishment = Establishment::query()->find($entityId);
        abort_unless($establishment, 422, 'Estabelecimento informado não foi encontrado.');
        abort_unless(
            (int) $establishment->app_id === $appId,
            422,
            'A aplicação do pedido deve ser a mesma aplicação do estabelecimento.'
        );

        $attendantId = (int) $request->input('attendant_id');
        if ($attendantId > 0) {
            $employer = Employer::query()->find($attendantId);
            abort_unless($employer, 422, 'Colaborador informado não foi encontrado.');
            abort_unless(
                (int) $employer->establishment_id === $entityId,
                422,
                'O colaborador deve pertencer ao estabelecimento do pedido.'
            );
        }

        $itemIds = collect($request->input('items', []))
            ->pluck('item_id')
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($itemIds->isNotEmpty()) {
            $validItemIds = Item::query()
                ->whereIn('id', $itemIds)
                ->where('app_id', $appId)
                ->where('entity_name', 'establishment')
                ->where('entity_id', $entityId)
                ->where('status', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);

            abort_if(
                $itemIds->diff($validItemIds)->isNotEmpty(),
                422,
                'Todos os itens do pedido devem pertencer ao mesmo estabelecimento e aplicação.'
            );
        }

        return $next($request);
    }
}
