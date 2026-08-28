<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employer;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountContextController extends Controller
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('profile');

        $establishments = $user->establishments()
            ->where('app_id', $this->context->id())
            ->where('is_cancelled', false)
            ->get();
        $establishments->each->setAppends([]);

        $employments = Employer::query()
            ->with(['establishment' => fn ($query) => $query->where('app_id', $this->context->id())])
            ->where('user_id', $user->id)
            ->whereIn('establishment_id', function ($query) {
                $query->select('id')
                    ->from('establishments')
                    ->where('app_id', $this->context->id())
                    ->where('is_cancelled', false);
            })
            ->get();
        $employments->each->setAppends([]);

        $membership = $user->applications()
            ->where('applications.id', $this->context->id())
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'application' => [
                    'id' => $this->context->id(),
                    'slug' => $this->context->slug(),
                    'name' => $this->context->application()->name,
                ],
                'user' => $user,
                'membership' => $membership?->pivot,
                'establishments' => $establishments,
                'employments' => $employments,
                // Compatibility for clients still expecting one employment.
                'employer' => $employments->first(),
                'is_employer' => $employments->isNotEmpty(),
            ],
        ]);
    }
}
