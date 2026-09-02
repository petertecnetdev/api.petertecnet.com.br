<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\People\Services\TeamService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TeamResource;
use App\Models\Team;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

final class TeamController extends Controller
{
    public function __construct(private readonly TeamService $teams) {}

    public function index(Request $request)
    {
        $paginator = $this->teams->paginate($request->only(['organization_id','type','q','per_page']));
        return ApiResponse::paginated($paginator, TeamResource::collection($paginator->getCollection())->resolve($request));
    }

    public function show(Request $request, int $team)
    {
        $model = Team::query()->with('members')->findOrFail($team);
        return ApiResponse::success((new TeamResource($model))->resolve($request));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'organization_id' => ['nullable','integer','exists:organizations,id'],
            'name' => ['required','string','min:2','max:180'],
            'slug' => ['nullable','string','max:190'],
            'type' => ['nullable','string','max:40'],
            'metadata' => ['nullable','array'],
        ]);
        $model = $this->teams->create($data, $request->user()?->id);
        return ApiResponse::success((new TeamResource($model))->resolve($request), [], 201);
    }

    public function syncMembers(Request $request, int $team)
    {
        $data = $request->validate([
            'members' => ['required','array','max:100'],
            'members.*.person_profile_id' => ['required','integer','distinct'],
            'members.*.role' => ['nullable','string','max:60'],
            'members.*.status' => ['nullable','in:active,inactive'],
            'members.*.metadata' => ['nullable','array'],
        ]);
        $model = Team::query()->findOrFail($team);
        return ApiResponse::success((new TeamResource($this->teams->syncMembers($model, $data['members'])))->resolve($request));
    }
}
