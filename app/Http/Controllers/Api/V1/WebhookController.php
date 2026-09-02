<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiProject;
use App\Models\WebhookEndpoint;
use App\Support\ApiResponse;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function index(Request $request, ApiProject $project)
    {
        $this->assertOwner($request, $project);
        return ApiResponse::success($project->webhooks()->get()->makeHidden(['secret']));
    }

    public function store(Request $request, ApiProject $project)
    {
        $this->assertOwner($request, $project);
        $data = $request->validate([
            'url' => 'required|url|max:2048',
            'events' => 'required|array|min:1|max:100',
            'events.*' => 'string|max:120',
        ]);

        $secret = 'whsec_' . Str::lower(Str::random(48));
        $endpoint = $project->webhooks()->create([
            ...$data,
            'secret' => $secret,
            'is_active' => true,
        ]);

        return ApiResponse::success([
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'secret' => $secret,
            'warning' => 'O segredo de assinatura é exibido apenas nesta resposta.',
        ], [], 201);
    }

    public function update(Request $request, ApiProject $project, WebhookEndpoint $webhook)
    {
        $this->assertOwner($request, $project);
        abort_unless((int) $webhook->api_project_id === (int) $project->id, 404);
        $data = $request->validate([
            'url' => 'sometimes|required|url|max:2048',
            'events' => 'sometimes|required|array|min:1|max:100',
            'events.*' => 'string|max:120',
            'is_active' => 'sometimes|boolean',
        ]);
        $webhook->update($data);
        return ApiResponse::success($webhook->fresh()->makeHidden(['secret']));
    }

    public function destroy(Request $request, ApiProject $project, WebhookEndpoint $webhook)
    {
        $this->assertOwner($request, $project);
        abort_unless((int) $webhook->api_project_id === (int) $project->id, 404);
        $webhook->delete();
        return ApiResponse::success(['deleted' => true]);
    }

    private function assertOwner(Request $request, ApiProject $project): void
    {
        abort_unless((int) $project->application_id === $this->context->id(), 404);
        abort_unless((int) $project->owner_user_id === (int) $request->user()->id || $request->user()->hasProfile('Administrador'), 403);
    }
}
