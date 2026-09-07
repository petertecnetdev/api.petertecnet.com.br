<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Domain\Platform\Services\ApplicationAdminOverviewService;
use App\Domain\Platform\Services\ApplicationAdminService;
use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ApplicationAdminController extends Controller
{
    public function __construct(
        private readonly ApplicationAdminService $service,
        private readonly ApplicationAdminOverviewService $overviewService,
    ) {
    }

    public function context(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->context($request->user(), $this->applicationId($request)),
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->overviewService->overview($this->applicationId($request)),
        ]);
    }

    public function permissionCatalog(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->permissionCatalog(),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $applicationId = $this->applicationId($request);
        $needle = trim((string) ($validated['q'] ?? ''));

        $query = User::query()
            ->select(['id', 'first_name', 'last_name', 'user_name', 'email', 'phone', 'city', 'uf', 'avatar', 'created_at'])
            ->whereHas('applications', fn ($applicationQuery) => $applicationQuery->whereKey($applicationId))
            ->with(['applications' => fn ($applicationQuery) => $applicationQuery
                ->whereKey($applicationId)
                ->select(['applications.id', 'applications.slug', 'applications.name'])]);

        if ($needle !== '') {
            $query->where(function ($userQuery) use ($needle) {
                $userQuery->where('first_name', 'like', "%{$needle}%")
                    ->orWhere('last_name', 'like', "%{$needle}%")
                    ->orWhere('email', 'like', "%{$needle}%")
                    ->orWhere('user_name', 'like', "%{$needle}%");
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest('id')->paginate($validated['per_page'] ?? 25),
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['nullable', 'string', 'in:participant,producer,production_manager,artist,promoter,ticket_manager'],
        ]);

        $applicationId = $this->applicationId($request);
        $email = strtolower(trim($validated['email']));
        $temporaryPassword = Str::random(14).'Aa1!';
        $verificationCode = strtoupper(Str::random(6));
        $created = false;

        $user = DB::transaction(function () use ($validated, $email, $temporaryPassword, $verificationCode, $applicationId, &$created) {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if (! $user) {
                $created = true;
                $user = User::query()->create([
                    'first_name' => trim($validated['first_name']),
                    'last_name' => trim((string) ($validated['last_name'] ?? '')) ?: null,
                    'email' => $email,
                    'user_name' => $this->uniqueUsername($validated['first_name']),
                    'password' => Hash::make($temporaryPassword),
                    'verification_code' => Hash::make($verificationCode),
                    'verification_code_expires_at' => now()->addDay(),
                    'is_participant' => true,
                    'is_producer' => in_array(($validated['role'] ?? 'participant'), ['producer', 'production_manager', 'ticket_manager'], true),
                    'is_promoter' => ($validated['role'] ?? 'participant') === 'promoter',
                ]);
            }

            $role = $validated['role'] ?? 'participant';
            DB::table('application_user')->updateOrInsert(
                ['application_id' => $applicationId, 'user_id' => $user->id],
                [
                    'role' => $role,
                    'status' => 'active',
                    'metadata' => json_encode(['roles' => [$role]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'joined_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            return $user->fresh(['applications']);
        });

        if ($created) {
            Mail::to($user->email)->send(new WelcomeMail($verificationCode, $user, $temporaryPassword));
        }

        $this->service->recordAudit(
            $applicationId,
            $request->user(),
            $user,
            $created ? 'application_user_created' : 'application_user_linked',
            ['role' => $validated['role'] ?? 'participant'],
            $this->auditContext($request),
        );

        return response()->json([
            'success' => true,
            'message' => $created
                ? 'Usuário criado e vinculado à aplicação com sucesso.'
                : 'Usuário existente vinculado à aplicação com sucesso.',
            'data' => $user,
        ], $created ? 201 : 200);
    }

    public function profiles(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->profiles($this->applicationId($request), $request->user()),
        ]);
    }

    public function storeProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', 'string', 'max:120'],
        ]);

        $profile = $this->service->createProfile(
            $this->applicationId($request),
            $request->user(),
            $validated,
            $this->auditContext($request),
        );

        return response()->json(['success' => true, 'data' => $profile], 201);
    }

    public function updateProfile(Request $request, int $profileId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'permissions' => ['sometimes', 'required', 'array'],
            'permissions.*' => ['required', 'string', 'max:120'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->updateProfile(
                $this->applicationId($request),
                $profileId,
                $request->user(),
                $validated,
                $this->auditContext($request),
            ),
        ]);
    }

    public function destroyProfile(Request $request, int $profileId): JsonResponse
    {
        $this->service->deleteProfile(
            $this->applicationId($request),
            $profileId,
            $request->user(),
            $this->auditContext($request),
        );

        return response()->json(['success' => true]);
    }

    public function assignments(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->assignments($this->applicationId($request), $request->user()),
        ]);
    }

    public function assign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'profile_id' => ['required', 'integer', 'min:1'],
        ]);

        $assignment = $this->service->assign(
            $this->applicationId($request),
            $request->user(),
            $validated['email'],
            (int) $validated['profile_id'],
            $this->auditContext($request),
        );

        return response()->json(['success' => true, 'data' => $assignment], 201);
    }

    public function revoke(Request $request, int $assignmentId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->revoke(
                $this->applicationId($request),
                $assignmentId,
                $request->user(),
                $this->auditContext($request),
            ),
        ]);
    }

    public function audit(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->audit(
                $this->applicationId($request),
                $request->user(),
                (int) $request->integer('per_page', 50),
            ),
        ]);
    }

    private function applicationId(Request $request): int
    {
        return (int) $request->attributes->get('app_id');
    }

    private function auditContext(Request $request): array
    {
        return [
            'request_id' => $request->attributes->get('request_id') ?: $request->header('X-Request-ID'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }

    private function uniqueUsername(string $name): string
    {
        $base = Str::slug($name) ?: 'user';
        do {
            $username = $base.'-'.strtolower(Str::random(6));
        } while (User::query()->where('user_name', $username)->exists());

        return $username;
    }
}
