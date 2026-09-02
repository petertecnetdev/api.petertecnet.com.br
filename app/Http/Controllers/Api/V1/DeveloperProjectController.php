<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiCredential;
use App\Models\ApiProject;
use App\Models\OauthClient;
use App\Support\ApiResponse;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

class DeveloperProjectController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function index(Request $request)
    {
        $this->assertApplicationAccess($request);
        $projects = ApiProject::query()
            ->where('application_id', $this->context->id())
            ->where('owner_user_id', $request->user()->id)
            ->withCount(['credentials', 'webhooks'])
            ->latest()
            ->get();

        return ApiResponse::success($projects);
    }

    public function store(Request $request)
    {
        $this->assertApplicationAccess($request);
        $data = $request->validate([
            'name' => 'required|string|max:180',
            'environment' => 'required|in:sandbox,production',
            'allowed_scopes' => 'required|array|min:1|max:100',
            'allowed_scopes.*' => 'string|max:120',
            'requests_per_minute' => 'nullable|integer|min:1|max:10000',
            'monthly_request_quota' => 'nullable|integer|min:1|max:1000000000',
        ]);

        $project = ApiProject::create([
            ...$data,
            'application_id' => $this->context->id(),
            'owner_user_id' => $request->user()->id,
        ]);

        return ApiResponse::success($project, [], 201);
    }

    public function issueApiKey(Request $request, ApiProject $project)
    {
        $this->assertProjectOwner($request, $project);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'scopes' => 'required|array|min:1|max:100',
            'scopes.*' => 'string|max:120',
        ]);
        $this->assertScopes($project, $data['scopes']);
        [$credential, $secret] = ApiCredential::issue($project, $data['name'], $data['scopes']);

        return ApiResponse::success([
            'id' => $credential->id,
            'name' => $credential->name,
            'key' => $secret,
            'scopes' => $credential->scopes,
            'warning' => 'A chave completa é exibida apenas uma vez.',
        ], [], 201);
    }

    public function issueOauthClient(Request $request, ApiProject $project)
    {
        $this->assertProjectOwner($request, $project);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'scopes' => 'required|array|min:1|max:100',
            'scopes.*' => 'string|max:120',
        ]);
        $this->assertScopes($project, $data['scopes']);
        [$client, $secret] = OauthClient::issue($project, $data['name'], $data['scopes']);

        return ApiResponse::success([
            'client_id' => $client->client_id,
            'client_secret' => $secret,
            'scopes' => $client->scopes,
            'token_endpoint' => url('/api/v1/oauth/token'),
            'warning' => 'O client_secret completo é exibido apenas uma vez.',
        ], [], 201);
    }

    public function usage(Request $request, ApiProject $project)
    {
        $this->assertProjectOwner($request, $project);
        $from = now()->startOfMonth();
        $query = $project->hasMany(\App\Models\ApiUsageRecord::class)->where('occurred_at', '>=', $from);

        return ApiResponse::success([
            'period_start' => $from->toIso8601String(),
            'requests' => (clone $query)->count(),
            'errors' => (clone $query)->where('status_code', '>=', 400)->count(),
            'average_duration_ms' => round((float) ((clone $query)->avg('duration_ms') ?? 0), 2),
            'quota' => $project->monthly_request_quota,
        ]);
    }

    private function assertApplicationAccess(Request $request): void
    {
        abort_unless($request->user()->applications()->whereKey($this->context->id())->exists() || $request->user()->hasProfile('Administrador'), 403);
    }

    private function assertProjectOwner(Request $request, ApiProject $project): void
    {
        $this->assertApplicationAccess($request);
        abort_unless((int) $project->application_id === $this->context->id(), 404);
        abort_unless((int) $project->owner_user_id === (int) $request->user()->id || $request->user()->hasProfile('Administrador'), 403);
    }

    private function assertScopes(ApiProject $project, array $scopes): void
    {
        foreach ($scopes as $scope) abort_unless($project->allows($scope), 422, 'Escopo não permitido pelo projeto: ' . $scope);
    }
}
