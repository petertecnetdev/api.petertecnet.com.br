<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employer;
use App\Models\Establishment;
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

        // Application membership is a relationship, not a numeric app_id equality.
        // This keeps one establishment reusable across multiple applications.
        $establishments = Establishment::query()
            ->forApplication($this->context->id())
            ->where('user_id', $user->id)
            ->where('is_cancelled', false)
            ->latest('id')
            ->get();
        $establishments->each->setAppends([]);

        $employments = Employer::query()
            ->with('establishment')
            ->where('user_id', $user->id)
            ->whereHas('establishment', function ($query) {
                $query
                    ->forApplication($this->context->id())
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
