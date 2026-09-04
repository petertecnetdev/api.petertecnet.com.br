<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EcosystemAuditLog;
use App\Models\Establishment;
use App\Models\User;
use App\Services\AdminActionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminCopilotController extends Controller
{
    public function capabilities(Request $request, AdminActionRegistry $registry): JsonResponse
    {
        $this->authorizeCopilot($request);
        return response()->json([
            'version' => 1,
            'capabilities' => $registry->publicCapabilities(),
            'safety' => [
                'destructive_voice_actions' => false,
                'requires_confirmation' => ['confirm', 'strong-confirm'],
                'audit_source' => 'admin_copilot',
            ],
        ]);
    }

    public function preflight(Request $request, AdminActionRegistry $registry): JsonResponse
    {
        $this->authorizeCopilot($request);
        $data = $request->validate([
            'actions' => ['required', 'array', 'min:1', 'max:25'],
            'actions.*.key' => ['required', 'string', 'max:100'],
            'actions.*.payload' => ['required', 'array'],
            'context' => ['nullable', 'array'],
        ]);

        $resolved = [];
        $highestRisk = 'automatic';
        $riskWeight = ['automatic' => 0, 'confirm' => 1, 'strong-confirm' => 2, 'blocked' => 3];
        $virtual = $this->virtualContext($data['context'] ?? []);

        foreach ($data['actions'] as $index => $action) {
            $definition = $registry->find($action['key']);
            if (! $definition) {
                throw ValidationException::withMessages([
                    "actions.$index.key" => ['Esta capacidade administrativa não está registrada.'],
                ]);
            }

            $payload = $action['payload'];
            foreach ($definition['required'] as $field) {
                $value = $payload[$field] ?? null;
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    throw ValidationException::withMessages([
                        "actions.$index.payload.$field" => ["O campo {$field} é obrigatório para {$definition['label']}."],
                    ]);
                }
            }

            $normalized = $this->resolveAction($action['key'], $payload, $virtual);
            $resolvedAction = [
                'key' => $action['key'],
                'label' => $definition['label'],
                'risk' => $definition['risk'],
                'executor' => $definition['executor'],
                'payload' => $normalized['payload'],
                'resolved' => $normalized['resolved'],
                'warnings' => $normalized['warnings'],
            ];
            $resolved[] = $resolvedAction;
            $this->rememberVirtual($virtual, $resolvedAction);

            if (($riskWeight[$definition['risk']] ?? 0) > ($riskWeight[$highestRisk] ?? 0)) {
                $highestRisk = $definition['risk'];
            }
        }

        return response()->json([
            'valid' => true,
            'risk' => $highestRisk,
            'actions' => $resolved,
            'requires_confirmation' => $highestRisk !== 'automatic',
            'compound' => count($resolved) > 1,
        ]);
    }

    public function audit(Request $request): JsonResponse
    {
        $this->authorizeCopilot($request);
        $data = $request->validate([
            'status' => ['required', 'string', 'in:success,error,cancelled'],
            'transcript' => ['nullable', 'string', 'max:10000'],
            'plan' => ['required', 'array'],
            'result' => ['nullable', 'array'],
            'session_id' => ['nullable', 'string', 'max:120'],
        ]);

        $actor = $request->user();
        $entry = EcosystemAuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'admin_copilot.' . $data['status'],
            'entity_type' => 'admin_copilot',
            'entity_id' => null,
            'before' => null,
            'after' => $this->redact([
                'source' => 'admin_copilot',
                'session_id' => $data['session_id'] ?? null,
                'transcript' => $data['transcript'] ?? null,
                'plan' => $data['plan'],
                'result' => $data['result'] ?? null,
            ]),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);

        return response()->json(['logged' => true, 'id' => $entry->id], 201);
    }

    private function resolveAction(string $key, array $payload, array $virtual): array
    {
        return match ($key) {
            'user.invite' => $this->resolveUserInvite($payload),
            'establishment.create' => $this->resolveEstablishmentCreate($payload, $virtual),
            'item.create' => $this->resolveItemCreate($payload, $virtual),
            'ecosystem.search', 'admin.navigate' => ['payload' => $payload, 'resolved' => [], 'warnings' => []],
            default => throw ValidationException::withMessages(['action' => ['Ação administrativa sem resolvedor.']]),
        };
    }

    private function resolveUserInvite(array $payload): array
    {
        $application = $this->resolveApplication((string) $payload['application']);
        $email = strtolower(trim((string) $payload['email']));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => ['Informe um e-mail válido.']]);
        }

        $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $warnings = [];
        if ($existing) {
            $warnings[] = 'Este e-mail já pertence a um usuário. O onboarding reutilizará a conta existente e emitirá um novo convite para a aplicação.';
        }

        return [
            'payload' => [...$payload, 'email' => $email, 'application' => $application->id],
            'resolved' => [
                'application' => $application->only(['id', 'name', 'slug']),
                'existing_user' => $existing?->only(['id', 'first_name', 'last_name', 'user_name', 'email']),
            ],
            'warnings' => $warnings,
        ];
    }

    private function resolveEstablishmentCreate(array $payload, array $virtual): array
    {
        $application = $this->resolveApplication((string) $payload['application']);
        $ownerReference = (string) $payload['owner'];
        $virtualOwner = $this->findVirtual($virtual['users'], $ownerReference, ['id', 'email', 'name']);
        $owner = $virtualOwner ?: $this->userReference($ownerReference);
        $warnings = [];

        if (! empty($payload['cnpj'])) {
            $duplicate = Establishment::query()->where('cnpj', trim((string) $payload['cnpj']))->first();
            if ($duplicate) $warnings[] = "Já existe um estabelecimento com esse CNPJ (#{$duplicate->id} {$duplicate->name}).";
        }

        return [
            'payload' => [
                ...$payload,
                'application' => $application->id,
                'owner' => $owner['id'] ?? $owner['email'] ?? $ownerReference,
            ],
            'resolved' => [
                'application' => $application->only(['id', 'name', 'slug']),
                'owner' => $owner,
            ],
            'warnings' => $warnings,
        ];
    }

    private function resolveItemCreate(array $payload, array $virtual): array
    {
        $application = $this->resolveApplication((string) $payload['application']);
        $reference = (string) $payload['establishment'];
        $virtualEstablishment = $this->findVirtual($virtual['establishments'], $reference, ['id', 'name', 'fantasy']);
        $establishment = $virtualEstablishment ?: $this->establishmentReference($reference);
        if (! is_numeric($payload['price'])) {
            throw ValidationException::withMessages(['price' => ['Informe um valor numérico válido.']]);
        }

        return [
            'payload' => [
                ...$payload,
                'application' => $application->id,
                'establishment' => $establishment['id'] ?? $establishment['name'] ?? $reference,
                'price' => (float) $payload['price'],
            ],
            'resolved' => [
                'application' => $application->only(['id', 'name', 'slug']),
                'establishment' => $establishment,
            ],
            'warnings' => [],
        ];
    }

    private function virtualContext(array $context): array
    {
        $virtual = ['users' => [], 'establishments' => []];
        if (! empty($context['user']) && is_array($context['user'])) {
            $virtual['users'][] = [...$context['user'], 'virtual' => false, 'source' => 'session_context'];
        }
        if (! empty($context['establishment']) && is_array($context['establishment'])) {
            $virtual['establishments'][] = [...$context['establishment'], 'virtual' => false, 'source' => 'session_context'];
        }
        return $virtual;
    }

    private function rememberVirtual(array &$virtual, array $action): void
    {
        if ($action['key'] === 'user.invite') {
            $existing = $action['resolved']['existing_user'] ?? null;
            $virtual['users'][] = [
                'id' => $existing['id'] ?? null,
                'name' => trim((string) ($action['payload']['name'] ?? '')),
                'email' => $action['payload']['email'] ?? null,
                'virtual' => ! $existing,
                'source' => 'plan',
            ];
        }
        if ($action['key'] === 'establishment.create') {
            $virtual['establishments'][] = [
                'id' => null,
                'name' => $action['payload']['name'] ?? null,
                'fantasy' => $action['payload']['fantasy'] ?? null,
                'virtual' => true,
                'source' => 'plan',
            ];
        }
    }

    private function findVirtual(array $rows, string $reference, array $fields): ?array
    {
        $needle = $this->normalizeReference($reference);
        $matches = collect($rows)->filter(function (array $row) use ($needle, $fields) {
            foreach ($fields as $field) {
                if (! array_key_exists($field, $row) || $row[$field] === null) continue;
                if ($this->normalizeReference((string) $row[$field]) === $needle) return true;
            }
            return false;
        })->values();
        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function resolveApplication(string $reference): Application
    {
        $reference = trim($reference);
        $rows = is_numeric($reference)
            ? Application::query()->whereKey((int) $reference)->get()
            : Application::query()->where(function ($q) use ($reference) {
                $q->whereRaw('LOWER(name) = ?', [strtolower($reference)])
                    ->orWhereRaw('LOWER(slug) = ?', [strtolower($reference)]);
            })->get();
        if ($rows->count() !== 1) {
            throw ValidationException::withMessages(['application' => ["Não foi possível identificar com segurança a aplicação “{$reference}”."]]);
        }
        return $rows->first();
    }

    private function userReference(string $reference): array
    {
        $reference = trim($reference);
        if (is_numeric($reference)) {
            $rows = User::query()->whereKey((int) $reference)->get();
        } elseif (filter_var($reference, FILTER_VALIDATE_EMAIL)) {
            $rows = User::query()->whereRaw('LOWER(email) = ?', [strtolower($reference)])->get();
        } else {
            $rows = User::query()->whereRaw('LOWER(user_name) = ?', [strtolower($reference)])
                ->orWhereRaw("LOWER(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) = ?", [strtolower($reference)])
                ->get();
        }
        if ($rows->count() !== 1) {
            throw ValidationException::withMessages(['owner' => ["Não foi possível identificar com segurança o usuário “{$reference}”."]]);
        }
        $user = $rows->first();
        return [
            'id' => $user->id,
            'name' => trim($user->first_name . ' ' . $user->last_name),
            'email' => $user->email,
            'user_name' => $user->user_name,
            'virtual' => false,
            'source' => 'database',
        ];
    }

    private function establishmentReference(string $reference): array
    {
        $reference = trim($reference);
        if (is_numeric($reference)) {
            $rows = Establishment::query()->whereKey((int) $reference)->get();
        } else {
            $rows = Establishment::query()->where(function ($q) use ($reference) {
                $needle = strtolower($reference);
                $q->whereRaw('LOWER(name) = ?', [$needle])
                    ->orWhereRaw('LOWER(fantasy) = ?', [$needle])
                    ->orWhereRaw('LOWER(slug) = ?', [$needle])
                    ->orWhere('cnpj', $reference);
            })->get();
        }
        if ($rows->count() !== 1) {
            throw ValidationException::withMessages(['establishment' => ["Não foi possível identificar com segurança o estabelecimento “{$reference}”."]]);
        }
        $establishment = $rows->first();
        return [
            'id' => $establishment->id,
            'name' => $establishment->name,
            'fantasy' => $establishment->fantasy,
            'cnpj' => $establishment->cnpj,
            'virtual' => false,
            'source' => 'database',
        ];
    }

    private function normalizeReference(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }

    private function authorizeCopilot(Request $request): void
    {
        $actor = $request->user();
        abort_unless(
            $actor && ($actor->hasProfile('Administrador') || $actor->hasPermission('user_create') || $actor->hasPermission('application_manage')),
            403,
            'Você não tem permissão para usar o Admin Copilot.'
        );
    }

    private function redact(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        $sensitive = ['password', 'password_confirmation', 'temporary_password', 'token', 'verification_code', 'code'];
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = in_array((string) $key, $sensitive, true) ? '[REDACTED]' : $this->redact($item);
        }
        return $result;
    }
}
