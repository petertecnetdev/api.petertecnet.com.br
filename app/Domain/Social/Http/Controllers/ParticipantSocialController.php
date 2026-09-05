<?php

namespace App\Domain\Social\Http\Controllers;

use App\Domain\Social\Services\ParticipantSocialService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class ParticipantSocialController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ParticipantSocialService $service,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'interest' => 'nullable|string|max:80',
            'per_page' => 'nullable|integer|min:6|max:48',
        ]);

        return response()->json($this->service->index($this->context->id(), $request->user(), $data));
    }

    public function show(Request $request, int $participantId)
    {
        return response()->json($this->service->show($this->context->id(), $request->user(), $participantId));
    }

    public function activity(Request $request)
    {
        $data = $request->validate(['per_page' => 'nullable|integer|min:5|max:30']);
        return response()->json($this->service->activity(
            $this->context->id(),
            (int) $request->user()->id,
            (int) ($data['per_page'] ?? 12),
        ));
    }

    public function settings(Request $request)
    {
        return response()->json([
            'settings' => $this->service->settings((int) $request->user()->id, $this->context->id()),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'discoverable' => 'required|boolean',
            'show_city' => 'required|boolean',
            'show_interests' => 'required|boolean',
            'show_event_interests' => 'required|boolean',
            'allow_follows' => 'required|boolean',
        ]);

        return response()->json([
            'message' => 'Preferências sociais atualizadas.',
            'settings' => $this->service->updateSettings((int) $request->user()->id, $this->context->id(), $data),
        ]);
    }

    public function follow(Request $request, int $participantId)
    {
        return response()->json($this->service->follow($this->context->id(), $request->user(), $participantId));
    }

    public function unfollow(Request $request, int $participantId)
    {
        return response()->json($this->service->unfollow($this->context->id(), (int) $request->user()->id, $participantId));
    }
}
