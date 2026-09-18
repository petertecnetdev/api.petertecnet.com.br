<?php

namespace App\Domain\People\Http\Controllers;

use App\Domain\People\Services\ArtistInvitationWorkflowService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ArtistInvitationController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ArtistInvitationWorkflowService $workflow,
    ) {
    }

    public function resolve(Request $request, int $eventId): JsonResponse
    {
        $data = $request->validate([
            'identifier' => 'required|string|min:2|max:190',
            'participation_type' => 'required|string|max:80',
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0|max:1000',
            'scheduled_at' => 'nullable|date',
            'stage' => 'nullable|string|max:160',
            'is_headliner' => 'nullable|boolean',
            'fee_cents' => 'nullable|integer|min:0|max:9999999999',
            'private_notes' => 'nullable|string|max:5000',
        ]);

        $identifier = $data['identifier'];
        unset($data['identifier']);

        $result = $this->workflow->inviteByIdentifier(
            $this->context->id(),
            $eventId,
            $request->user(),
            $identifier,
            $data
        );

        return response()->json($result, ($result['external_invitation'] ?? false) ? 202 : 201);
    }

    public function claimPending(Request $request): JsonResponse
    {
        return response()->json(
            $this->workflow->claimPending($this->context->id(), $request->user())
        );
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json(
            $this->workflow->mine(
                $this->context->id(),
                $request->user(),
                (int) $request->input('per_page', 30)
            )
        );
    }

    public function show(Request $request, string $token): JsonResponse
    {
        return response()->json(
            $this->workflow->showForUser($this->context->id(), $token, $request->user())
        );
    }

    public function respond(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'decision' => 'required|in:accept,reject',
            'decline_reason' => 'nullable|string|max:500',
        ]);

        return response()->json(
            $this->workflow->respondByToken(
                $this->context->id(),
                $token,
                $request->user(),
                $data['decision'],
                $data['decline_reason'] ?? null,
                $request->ip(),
                $request->userAgent()
            )
        );
    }

    public function eventIndex(Request $request, int $eventId): JsonResponse
    {
        return response()->json(
            $this->workflow->producerEventInvitations(
                $this->context->id(),
                $eventId,
                $request->user()
            )
        );
    }

    public function resend(Request $request, int $eventId, int $invitationId): JsonResponse
    {
        return response()->json(
            $this->workflow->resend(
                $this->context->id(),
                $eventId,
                $invitationId,
                $request->user()
            )
        );
    }

    public function cancel(Request $request, int $eventId, int $invitationId): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        return response()->json(
            $this->workflow->cancel(
                $this->context->id(),
                $eventId,
                $invitationId,
                $request->user(),
                $data['reason'] ?? null
            )
        );
    }

    public function calendar(Request $request, string $token): Response
    {
        $ics = $this->workflow->calendarForUser(
            $this->context->id(),
            $token,
            $request->user()
        );

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="evento-artista.ics"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function trackOpen(string $token): Response
    {
        $this->workflow->trackOpen($this->context->id(), $token);

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');

        return response($gif, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function emailDelivery(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:delivered,bounced,failed',
        ]);

        return response()->json(
            $this->workflow->recordEmailDeliveryEvent(
                $this->context->id(),
                $token,
                $data['status'],
                (string) $request->header('X-Artist-Invitation-Signature')
            )
        );
    }
}
