<?php

namespace App\Domain\People\Http\Controllers;

use App\Domain\People\Services\ArtistOnboardingService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ArtistOnboardingController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ArtistOnboardingService $onboarding,
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json(
            $this->onboarding->status($this->context->id(), $request->user())
        );
    }

    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stage_name' => 'nullable|string|min:2|max:255',
            'genres' => 'nullable|array|max:10',
            'genres.*' => 'string|max:80',
            'photo' => 'nullable|string|max:2048',
            'short_bio' => 'nullable|string|max:500',
        ]);

        return response()->json(
            $this->onboarding->activate($this->context->id(), $request->user(), $data)
        );
    }

    public function referenceVisibility(Request $request, int $artistId): JsonResponse
    {
        $data = $request->validate([
            'visible' => 'required|boolean',
        ]);

        return response()->json([
            'artist' => $this->onboarding->setReferenceVisibility(
                $this->context->id(),
                $request->user(),
                $artistId,
                (bool) $data['visible']
            ),
        ]);
    }

    public function claimCandidates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => 'required|string|min:2|max:160',
        ]);

        return response()->json([
            'artists' => $this->onboarding->claimCandidates($this->context->id(), $data['q']),
        ]);
    }

    public function claimExisting(Request $request, int $artistId): JsonResponse
    {
        $data = $request->validate([
            'evidence_text' => 'nullable|string|max:3000',
            'evidence_url' => 'nullable|url|max:2048',
        ]);

        return response()->json(
            $this->onboarding->claimExisting(
                $this->context->id(),
                $request->user(),
                $artistId,
                $data
            ),
            202
        );
    }

    public function claimQueue(Request $request): JsonResponse
    {
        return response()->json([
            'claims' => $this->onboarding->claimQueue($this->context->id(), $request->user()),
        ]);
    }

    public function reviewClaim(Request $request, int $claimId): JsonResponse
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'review_notes' => 'nullable|string|max:3000',
        ]);

        return response()->json(
            $this->onboarding->reviewClaim(
                $this->context->id(),
                $request->user(),
                $claimId,
                $data
            )
        );
    }
}
