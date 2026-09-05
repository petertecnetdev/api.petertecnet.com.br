<?php

namespace App\Services;

use App\Http\Controllers\OrderController;
use App\Mail\NewEmployerCollaborator;
use App\Mail\OwnerNotifiedNewCollaborator;
use App\Models\EcosystemAuditLog;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class AdminEstablishmentResourceService
{
    public function context(Establishment $establishment, int $appId): array
    {
        $this->assertApplicationLinked($establishment, $appId);
        $employers = Employer::query()
            ->where('establishment_id', $establishment->id)
            ->with('user:id,first_name,last_name,user_name,email,phone,avatar')
            ->orderBy('role')->orderBy('id')
            ->get(['id', 'user_id', 'establishment_id', 'role', 'permissions'])
            ->map(fn (Employer $employer) => [
                'id' => $employer->id,
                'user_id' => $employer->user_id,
                'role' => $employer->role,
                'permissions' => $employer->permissions ?? [],
                'user' => $employer->user,
            ]);
        $items = Item::query()
            ->where('entity_name', 'establishment')->where('entity_id', $establishment->id)
            ->where('app_id', $appId)->where('status', true)
            ->orderByRaw("CASE WHEN type = 'service' THEN 0 ELSE 1 END")->orderBy('name')
            ->get(['id', 'name', 'type', 'price', 'duration', 'category', 'status']);

        return [
            'establishment' => [
                'id' => $establishment->id, 'user_id' => $establishment->user_id,
                'name' => $establishment->name, 'fantasy' => $establishment->fantasy,
                'slug' => $establishment->slug, 'app_id' => $appId,
            ],
            'employers' => $employers,
            'items' => $items,
        ];
    }

    public function storeEmployer(Request $request, Establishment $establishment, array $data): Employer
    {
        $this->assertApplicationLinked($establishment, (int) $data['app_id']);
        if ((int) $data['user_id'] === (int) $establishment->user_id) {
            throw ValidationException::withMessages(['user_id' => ['O proprietário não deve ser cadastrado como employer do próprio establishment.']]);
        }
        if (Employer::query()->where('user_id', $data['user_id'])->where('establishment_id', $establishment->id)->exists()) {
            throw ValidationException::withMessages(['user_id' => ['Este usuário já está vinculado ao establishment como employer.']]);
        }

        $actor = $request->user();
        $employer = Employer::create([
            'user_id' => (int) $data['user_id'], 'establishment_id' => $establishment->id,
            'role' => trim($data['role']), 'permissions' => array_values(array_unique($data['permissions'] ?? [])),
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ])->load(['user', 'establishment.user']);

        $this->audit($request, 'employer.created_from_admin_center', Employer::class, $employer->id, [
            'employer' => $employer->toArray(), 'app_id' => (int) $data['app_id'], 'establishment_id' => $establishment->id,
        ]);

        try {
            if ($employer->establishment?->user?->email) Mail::to($employer->establishment->user->email)->queue(new OwnerNotifiedNewCollaborator($employer->establishment, $employer));
            if ($employer->user?->email) Mail::to($employer->user->email)->queue(new NewEmployerCollaborator($employer->establishment, $employer));
        } catch (\Throwable $exception) {
            Log::warning('AdminEstablishmentResourceService.storeEmployer.mail', ['employer_id' => $employer->id, 'error' => $exception->getMessage()]);
        }
        return $employer;
    }

    public function storeAppointment(Request $request, Establishment $establishment, array $data)
    {
        $appId = (int) $data['app_id'];
        $this->assertApplicationLinked($establishment, $appId);
        $employer = Employer::query()->where('establishment_id', $establishment->id)->findOrFail((int) $data['attendant_id']);
        $itemIds = collect($data['items'])->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->values();
        $validItems = Item::query()->whereIn('id', $itemIds)->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)->where('app_id', $appId)->where('status', true)->count();
        if ($validItems !== $itemIds->count()) {
            throw ValidationException::withMessages(['items' => ['Um ou mais itens não pertencem a este establishment/aplicação ou estão inativos.']]);
        }
        $client = User::findOrFail((int) $data['client_id']);
        if ((int) $client->id === (int) $employer->user_id) {
            throw ValidationException::withMessages(['client_id' => ['O cliente não pode ser o mesmo usuário do employer selecionado.']]);
        }

        $synthetic = Request::create('/internal/admin-center/appointment', 'POST', [
            'mode' => 'appointment', 'app_id' => $appId, 'entity_name' => 'establishment', 'entity_id' => $establishment->id,
            'items' => array_values($data['items']), 'origin' => 'admin_center', 'fulfillment' => 'appointment',
            'payment_status' => 'pending', 'payment_method' => $data['payment_method'], 'order_datetime' => $data['order_datetime'],
            'attendant_id' => $employer->id, 'notes' => $data['notes'] ?? null,
        ]);
        $synthetic->setUserResolver(fn () => $client);
        $synthetic->headers->set('User-Agent', (string) $request->userAgent());
        $synthetic->server->set('REMOTE_ADDR', (string) $request->ip());

        $response = app(OrderController::class)->store($synthetic);
        $payload = method_exists($response, 'getData') ? $response->getData(true) : [];
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $orderId = (int) ($payload['order']['id'] ?? 0);
            $this->audit($request, 'appointment.created_from_admin_center', Order::class, $orderId ?: null, [
                'order_id' => $orderId ?: null, 'client_id' => $client->id, 'attendant_id' => $employer->id,
                'establishment_id' => $establishment->id, 'app_id' => $appId,
            ]);
        }
        return $response;
    }

    public function authorizeAccess(Request $request): void
    {
        $email = strtolower(trim((string) $request->user()?->email));
        abort_unless($email === 'petertecnet@gmail.com', 403, 'Apenas o administrador principal pode criar recursos por este painel.');
    }

    private function assertApplicationLinked(Establishment $establishment, int $appId): void
    {
        $linked = (int) $establishment->app_id === $appId || $establishment->applications()->where('applications.id', $appId)->exists();
        if (! $linked) throw ValidationException::withMessages(['app_id' => ['A aplicação informada não está vinculada a este establishment.']]);
    }

    private function audit(Request $request, string $action, string $entityType, ?int $entityId, array $after): void
    {
        EcosystemAuditLog::create([
            'user_id' => $request->user()?->id, 'action' => $action, 'entity_type' => $entityType,
            'entity_id' => $entityId, 'before' => null, 'after' => $after, 'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}
