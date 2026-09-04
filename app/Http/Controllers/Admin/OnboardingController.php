<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\InviteUserMail;
use App\Models\Application;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless(
            $actor && ($actor->hasProfile('Administrador') || $actor->hasPermission('user_create') || $actor->hasPermission('application_manage')),
            403,
            'Você não tem permissão para realizar onboarding de clientes.'
        );

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'app_id' => ['required', 'integer', 'exists:applications,id'],
            'dry_run' => ['sometimes', 'boolean'],
            'user' => ['nullable', 'array'],
            'user.first_name' => ['nullable', 'string', 'max:100'],
            'user.last_name' => ['nullable', 'string', 'max:100'],
            'establishment' => ['nullable', 'array'],
            'establishment.name' => ['required_with:establishment', 'string', 'max:255'],
            'establishment.fantasy' => ['nullable', 'string', 'max:255'],
            'establishment.cnpj' => ['nullable', 'string', 'max:30'],
            'establishment.phone' => ['nullable', 'string', 'max:40'],
            'establishment.email' => ['nullable', 'email', 'max:255'],
            'establishment.description' => ['nullable', 'string', 'max:5000'],
            'establishment.category' => ['nullable', 'string', 'max:150'],
            'establishment.type' => ['nullable', 'string', 'max:100'],
            'establishment.city' => ['nullable', 'string', 'max:120'],
            'establishment.uf' => ['nullable', 'string', 'size:2'],
            'establishment.address' => ['nullable', 'string', 'max:500'],
            'establishment.cep' => ['nullable', 'string', 'max:20'],
            'establishment.is_published' => ['nullable', 'boolean'],
            'establishment.is_approved' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.type' => ['required', Rule::in(['service', 'product', 'item', 'ticket'])],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.description' => ['nullable', 'string', 'max:5000'],
            'items.*.category' => ['nullable', 'string', 'max:150'],
            'items.*.subcategory' => ['nullable', 'string', 'max:150'],
            'items.*.brand' => ['nullable', 'string', 'max:150'],
            'items.*.duration' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'items.*.stock' => ['nullable', 'integer', 'min:0'],
            'items.*.status' => ['nullable', 'boolean'],
            'items.*.is_featured' => ['nullable', 'boolean'],
        ], [
            'email.required' => 'Informe o e-mail do cliente.',
            'email.email' => 'Informe um e-mail válido.',
            'app_id.required' => 'Selecione o aplicativo.',
            'app_id.exists' => 'O aplicativo selecionado não existe.',
            'establishment.name.required_with' => 'Informe o nome da empresa.',
            'establishment.uf.size' => 'A UF deve ter exatamente 2 caracteres.',
        ]);

        if (! empty($data['items']) && empty($data['establishment'])) {
            return response()->json(['message' => 'Crie o estabelecimento antes de adicionar itens.'], 422);
        }

        $application = Application::query()->findOrFail($data['app_id']);
        $email = strtolower(trim($data['email']));
        $existingUser = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ((bool) ($data['dry_run'] ?? false)) {
            $warnings = [];
            if ($existingUser) {
                $warnings[] = 'O e-mail já pertence a um usuário. A conta existente será reutilizada.';
            }
            if (! empty($data['establishment']['cnpj'])) {
                $duplicate = Establishment::query()->where('cnpj', $data['establishment']['cnpj'])->first();
                if ($duplicate) {
                    $warnings[] = "Já existe um estabelecimento com esse CNPJ (#{$duplicate->id} {$duplicate->name}).";
                }
            }

            return response()->json([
                'valid' => true,
                'dry_run' => true,
                'application' => $application->only(['id', 'name', 'slug', 'url']),
                'existing_user' => $existingUser?->only(['id', 'first_name', 'last_name', 'user_name', 'email']),
                'user' => $data['user'] ?? null,
                'establishment' => $data['establishment'] ?? null,
                'items_count' => count($data['items'] ?? []),
                'warnings' => $warnings,
            ]);
        }

        [$user, $establishment, $items, $createdUser, $issued] = DB::transaction(function () use ($data, $email, $actor, $application, $existingUser) {
            $user = $existingUser;
            $createdUser = ! $user;

            if (! $user) {
                $firstName = trim((string) data_get($data, 'user.first_name')) ?: $this->labelFromEmail($email);
                $lastName = trim((string) data_get($data, 'user.last_name')) ?: null;
                $user = User::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'user_name' => $this->uniqueUsername($email),
                    'password' => Hash::make(Str::random(64)),
                ]);
            }

            $existingAccess = $user->applications()->whereKey($application->id)->first();
            $existingStatus = $existingAccess?->pivot?->status;
            $existingRole = $existingAccess?->pivot?->role;
            $metadata = $existingAccess?->pivot?->metadata;
            if (is_string($metadata)) $metadata = json_decode($metadata, true) ?: [];
            if (! is_array($metadata)) $metadata = [];

            $metadata = array_merge($metadata, [
                'invited_by' => $actor->id,
                'invited_at' => now()->toIso8601String(),
                'source' => 'admin_managed_onboarding',
            ]);

            $user->applications()->syncWithoutDetaching([
                $application->id => [
                    'status' => $existingStatus === 'active' ? 'active' : 'pending',
                    'role' => $existingRole ?: 'client',
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                    'joined_at' => $existingStatus === 'active' ? ($existingAccess?->pivot?->joined_at ?: now()) : null,
                ],
            ]);

            $issued = app(InvitationService::class)->issue(
                $user,
                $application,
                $actor,
                ['source' => 'admin_managed_onboarding']
            );

            $establishment = null;
            $createdItems = collect();

            if (! empty($data['establishment'])) {
                $company = $data['establishment'];
                $establishment = Establishment::create([
                    'name' => trim($company['name']),
                    'fantasy' => trim((string) ($company['fantasy'] ?? '')) ?: trim($company['name']),
                    'cnpj' => $company['cnpj'] ?? null,
                    'phone' => $company['phone'] ?? null,
                    'email' => $company['email'] ?? $email,
                    'description' => $company['description'] ?? null,
                    'category' => $company['category'] ?? null,
                    'type' => $company['type'] ?? null,
                    'city' => $company['city'] ?? null,
                    'uf' => ! empty($company['uf']) ? strtoupper($company['uf']) : null,
                    'address' => $company['address'] ?? null,
                    'cep' => $company['cep'] ?? null,
                    'user_id' => $user->id,
                    'app_id' => $application->id,
                    'is_published' => (bool) ($company['is_published'] ?? true),
                    'is_approved' => (bool) ($company['is_approved'] ?? true),
                    'is_featured' => false,
                    'is_cancelled' => false,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $establishment->applications()->syncWithoutDetaching([
                    $application->id => ['is_primary' => true],
                ]);

                foreach ($data['items'] ?? [] as $itemData) {
                    $createdItems->push(Item::create([
                        'user_id' => $user->id,
                        'app_id' => $application->id,
                        'entity_id' => $establishment->id,
                        'entity_name' => 'establishment',
                        'name' => trim($itemData['name']),
                        'type' => $itemData['type'],
                        'price' => $itemData['price'],
                        'description' => $itemData['description'] ?? null,
                        'category' => $itemData['category'] ?? null,
                        'subcategory' => $itemData['subcategory'] ?? null,
                        'brand' => $itemData['brand'] ?? null,
                        'duration' => $itemData['duration'] ?? null,
                        'stock' => $itemData['stock'] ?? null,
                        'status' => (bool) ($itemData['status'] ?? true),
                        'is_featured' => (bool) ($itemData['is_featured'] ?? false),
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]));
                }
            }

            return [$user, $establishment, $createdItems, $createdUser, $issued];
        });

        try {
            Mail::to($user->email)->send(new InviteUserMail(
                $user,
                $issued['code'],
                $application->name,
                $application->url,
                $application->id,
                $issued['token']
            ));
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'O cadastro foi preparado, mas o e-mail de ativação não pôde ser enviado.',
                'user' => $user,
                'establishment' => $establishment,
                'items_count' => $items->count(),
                'created_user' => $createdUser,
                'mail_sent' => false,
            ], 201);
        }

        return response()->json([
            'message' => $createdUser
                ? 'Cliente criado e convite seguro de ativação enviado por e-mail.'
                : 'Usuário existente reutilizado e novo convite seguro enviado por e-mail.',
            'user' => $user->load('applications:id,name,slug,url'),
            'application' => $application->only(['id', 'name', 'slug', 'url']),
            'establishment' => $establishment,
            'items_count' => $items->count(),
            'created_user' => $createdUser,
            'mail_sent' => true,
            'invitation_expires_at' => $issued['invitation']->expires_at?->toIso8601String(),
        ], 201);
    }

    private function uniqueUsername(string $email): string
    {
        $local = Str::before($email, '@');
        $base = Str::slug($local, '_') ?: 'cliente';
        $candidate = $base;
        $counter = 1;

        while (User::query()->where('user_name', $candidate)->exists()) {
            $candidate = $base . '_' . $counter++;
        }

        return $candidate;
    }

    private function labelFromEmail(string $email): string
    {
        $local = Str::before($email, '@');
        $label = Str::of($local)->replace(['.', '_', '-'], ' ')->squish()->title()->toString();
        return Str::limit($label ?: 'Cliente', 100, '');
    }
}
