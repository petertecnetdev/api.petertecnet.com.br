<?php

namespace App\Http\Middleware;

use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Order;
use Closure;
use Illuminate\Http\Request;

class EnsureOrderAccess
{
    public function handle(Request $request, Closure $next, string $mode = 'order')
    {
        $user = $request->user();
        abort_unless($user, 401, 'Usuário não autenticado.');

        if ($user->hasProfile('Administrador')) {
            return $next($request);
        }

        if ($mode === 'entity') {
            $this->authorizeEntity($request, $user->id);
            return $next($request);
        }

        if ($mode === 'direct') {
            $entityId = (int) $request->input('entity_id');
            $entityName = (string) $request->input('entity_name', '');
            abort_unless($entityName === 'establishment' && $entityId > 0, 403, 'Entidade não autorizada.');
            $this->authorizeEstablishment($entityId, $user->id);
            return $next($request);
        }

        $orderId = (int) $request->route('id');
        $order = Order::findOrFail($orderId);

        $isClient = (int) $order->client_id === (int) $user->id;
        $isCreator = (int) $order->created_by === (int) $user->id;
        $isAttendant = $order->attendant_id
            ? Employer::whereKey($order->attendant_id)->where('user_id', $user->id)->exists()
            : false;
        $isEntityManager = $order->entity_name === 'establishment'
            ? $this->canManageEstablishment((int) $order->entity_id, (int) $user->id)
            : false;

        abort_unless($isClient || $isCreator || $isAttendant || $isEntityManager, 403, 'Você não pode acessar este pedido.');

        if ($mode === 'manage') {
            abort_unless($isCreator || $isAttendant || $isEntityManager, 403, 'Você não pode alterar este pedido.');
        }

        return $next($request);
    }

    private function authorizeEntity(Request $request, int $userId): void
    {
        $slug = (string) $request->route('slug');
        $establishment = Establishment::where('slug', $slug)->firstOrFail();
        abort_unless($this->canManageEstablishment($establishment->id, $userId), 403, 'Você não pode consultar pedidos desta empresa.');
    }

    private function authorizeEstablishment(int $establishmentId, int $userId): void
    {
        abort_unless($this->canManageEstablishment($establishmentId, $userId), 403, 'Você não pode criar pedidos diretos nesta empresa.');
    }

    private function canManageEstablishment(int $establishmentId, int $userId): bool
    {
        $establishment = Establishment::findOrFail($establishmentId);

        return (int) $establishment->user_id === $userId
            || (int) $establishment->created_by === $userId
            || $establishment->employers()->where('user_id', $userId)->exists();
    }
}
