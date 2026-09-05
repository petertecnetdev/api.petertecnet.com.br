<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventAnnouncementService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class EventAnnouncementController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EventAnnouncementService $announcements,
    ) {}

    public function publicIndex(string $slug)
    {
        return response()->json(
            $this->announcements->publicFeed($this->context->id(), $slug)
        );
    }

    public function manageIndex(Request $request, int $eventId)
    {
        return response()->json(
            $this->announcements->manageIndex($this->context->id(), $request->user(), $eventId)
        );
    }

    public function store(Request $request, int $eventId)
    {
        $announcement = $this->announcements->create(
            $this->context->id(),
            $request->user(),
            $eventId,
            $this->validated($request),
        );

        return response()->json([
            'message' => 'Aviso criado como rascunho.',
            'announcement' => $announcement,
        ], 201);
    }

    public function update(Request $request, int $eventId, int $announcementId)
    {
        $announcement = $this->announcements->update(
            $this->context->id(),
            $request->user(),
            $eventId,
            $announcementId,
            $this->validated($request),
        );

        return response()->json([
            'message' => 'Aviso atualizado.',
            'announcement' => $announcement,
        ]);
    }

    public function publish(Request $request, int $eventId, int $announcementId)
    {
        $result = $this->announcements->publish(
            $this->context->id(),
            $request->user(),
            $eventId,
            $announcementId,
        );

        return response()->json([
            'message' => $result['notified_count'] > 0
                ? "Aviso publicado e enviado para {$result['notified_count']} usuário(s)."
                : 'Aviso publicado.',
            ...$result,
        ]);
    }

    public function unpublish(Request $request, int $eventId, int $announcementId)
    {
        $announcement = $this->announcements->unpublish(
            $this->context->id(),
            $request->user(),
            $eventId,
            $announcementId,
        );

        return response()->json([
            'message' => 'Aviso retirado da página pública. Notificações já enviadas não são removidas.',
            'announcement' => $announcement,
        ]);
    }

    public function destroy(Request $request, int $eventId, int $announcementId)
    {
        $this->announcements->delete(
            $this->context->id(),
            $request->user(),
            $eventId,
            $announcementId,
        );

        return response()->json(['message' => 'Aviso excluído.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|min:3|max:140',
            'message' => 'required|string|min:3|max:5000',
            'level' => 'required|in:info,update,warning,critical',
            'audience' => 'required|in:all,interested,attendees',
            'is_pinned' => 'sometimes|boolean',
            'send_notification' => 'sometimes|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => $request->filled('starts_at') ? 'nullable|date|after:starts_at' : 'nullable|date',
        ]);
    }
}
