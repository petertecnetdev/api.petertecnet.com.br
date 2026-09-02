<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\People\Services\PeopleService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PeopleProfileResource;
use App\Models\PeopleProfile;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PeopleController extends Controller
{
    public function __construct(private readonly PeopleService $people) {}

    public function index(Request $request)
    {
        $paginator = $this->people->paginate($request->only(['kind','organization_id','q','per_page']));
        return ApiResponse::paginated($paginator, PeopleProfileResource::collection($paginator->getCollection())->resolve($request));
    }

    public function show(Request $request, int $person)
    {
        return ApiResponse::success((new PeopleProfileResource(PeopleProfile::query()->findOrFail($person)))->resolve($request));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['nullable','integer','exists:users,id'],
            'organization_id' => ['nullable','integer','exists:organizations,id'],
            'kind' => ['nullable', Rule::in(['person','professional','artist'])],
            'display_name' => ['required','string','min:2','max:180'],
            'slug' => ['nullable','string','max:190'],
            'metadata' => ['nullable','array'],
        ]);
        $profile = $this->people->create($data, $request->user()?->id);
        return ApiResponse::success((new PeopleProfileResource($profile))->resolve($request), [], 201);
    }

    public function update(Request $request, int $person)
    {
        $profile = PeopleProfile::query()->findOrFail($person);
        $data = $request->validate([
            'organization_id' => ['sometimes','nullable','integer','exists:organizations,id'],
            'kind' => ['sometimes', Rule::in(['person','professional','artist'])],
            'display_name' => ['sometimes','string','min:2','max:180'],
            'slug' => ['sometimes','nullable','string','max:190'],
            'status' => ['sometimes', Rule::in(['active','inactive'])],
            'metadata' => ['sometimes','array'],
        ]);
        return ApiResponse::success((new PeopleProfileResource($this->people->update($profile, $data)))->resolve($request));
    }
}
