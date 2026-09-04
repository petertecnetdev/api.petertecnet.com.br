<?php

namespace App\Services;

use App\Mail\InviteUserMail;
use App\Models\Application;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminOnboardingService
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function execute(User $actor, array $data): array
    {
        if (! empty($data['items']) && empty($data['establishment'])) {
            throw ValidationException::withMessages([
                'establishment' => ['Crie o estabelecimento antes de adicionar itens.'],
            ]);
        }

        $application = Application::query()->findOrFail($data['app_id']);
        $email = strtolower(trim($data['email']));
        $existingUser = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ((bool) ($data['dry_run'] ?? false)) {
            return ['status' => 200, 'payload' => $this->preview($data, $application, $existingUser)];
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

            $issued = $this->invitations->issue(
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

        $mailSent = true;
        try {
            Mail::to($user->email)->send(new InviteUserMail(
                $user,
                $issued['code'],
                $application->name,
                $application->url,
                $application->id,
                $issued['token']
            ));
        } catch (\Throwable) {
            $mailSent = false;
        }

        return [
            'status' => 201,
            'payload' => [
                'message' => $mailSent
                    ? ($createdUser
                        ? 'Cliente criado e convite seguro de ativação enviado por e-mail.'
                        : 'Usuário existente reutilizado e novo convite seguro enviado por e-mail.')
                    : 'O cadastro foi preparado, mas o e-mail de ativação não pôde ser enviado.',
                'user' => $user->load('applications:id,name,slug,url'),
                'application' => $application->only(['id', 'name', 'slug', 'url']),
                'establishment' => $establishment,
                'items_count' => $items->count(),
                'created_user' => $createdUser,
                'mail_sent' => $mailSent,
                'invitation_expires_at' => $issued['invitation']->expires_at?->toIso8601String(),
            ],
        ];
    }

    private function preview(array $data, Application $application, ?User $existingUser): array
    {
        $warnings = [];
        if ($existingUser) $warnings[] = 'O e-mail já pertence a um usuário. A conta existente será reutilizada.';
        if (! empty($data['establishment']['cnpj'])) {
            $duplicate = Establishment::query()->where('cnpj', $data['establishment']['cnpj'])->first();
            if ($duplicate) $warnings[] = "Já existe um estabelecimento com esse CNPJ (#{$duplicate->id} {$duplicate->name}).";
        }

        return [
            'valid' => true,
            'dry_run' => true,
            'application' => $application->only(['id', 'name', 'slug', 'url']),
            'existing_user' => $existingUser?->only(['id', 'first_name', 'last_name', 'user_name', 'email']),
            'user' => $data['user'] ?? null,
            'establishment' => $data['establishment'] ?? null,
            'items_count' => count($data['items'] ?? []),
            'warnings' => $warnings,
        ];
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
