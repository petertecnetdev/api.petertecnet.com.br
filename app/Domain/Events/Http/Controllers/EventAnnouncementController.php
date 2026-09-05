<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Services\EventAudienceService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class EventAnnouncementController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EventAudienceService $audience,
        private readonly AppNotificationService $notifications,
    ) {}

    public function publicIndex(string $slug)
    {
        $event = Event::query()
            ->where('app_id', $this->context->id())
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($q) => $q->where('is_private', false)->orWhereNull('is_private'))
            ->firstOrFail();

        $announcements = EventAnnouncement::query()
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id)
            ->visibleNow()
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(30)
            ->get($this->publicColumns());

        return response()->json([
            'announcements' => $announcements,
            'meta' => [
                'event_id' => $event->id,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function manageIndex(Request $request, int $eventId)
    {
        $event = $this->ownedEvent($eventId, $request->user());

        $announcements = EventAnnouncement::query()
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id)
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'slug' => $event->slug,
                'is_published' => (bool) $event->is_published,
                'is_cancelled' => (bool) $event->is_cancelled,
            ],
            'announcements' => $announcements,
        ]);
    }

    public function store(Request $request, int $eventId)
    {
        $event = $this->ownedEvent($eventId, $request->user());
        $data = $this->validated($request);

        $announcement = EventAnnouncement::create([
            ...$data,
            'app_id' => $this->context->id(),
            'event_id' => $event->id,
            'created_by' => $request->user()->id,
            'published_at' => null,
            'notification_sent_at' => null,
        ]);

        return response()->json([
            'message' => 'Aviso criado como rascunho.',
            'announcement' => $announcement,
        ], 201);
    }

    public function update(Request $request, int $eventId, int $announcementId)
    {
        $event = $this->ownedEvent($eventId, $request->user());
        $announcement = $this->announcement($event, $announcementId);
        $data = $this->validated($request);
        $announcement->update($data);

        return response()->json([
            'message' => 'Aviso atualizado.',
            'announcement' => $announcement->fresh(),
        ]);
    }

    public function publish(Request $request, int $eventId, int $announcementId)
    {
        $event = $this->ownedEvent($eventId, $request->user());
        abort_if($event->is_cancelled, 422, 'Não é possível publicar avisos em um evento cancelado.');
        abort_unless($event->is_published, 422, 'Publique o evento antes de publicar um aviso oficial.');

        $announcement = $this->announcement($event, $announcementId);
        if ($announcement->ends_at && Carbon::parse($announcement->ends_at)->lte(now())) {
            abort(422, 'O período de exibição deste aviso já terminou.');
        }

        if (! $announcement->published_at) {
            $announcement->forceFill(['published_at' => now()])->save();
        }

        $notifiedCount = 0;
        if ($announcement->send_notification && ! $announcement->notification_sent_at) {
            $userIds = $this->audience->audienceUserIds($event, $announcement->audience);
            $sent = $this->notifications->sendToUsers(
                (int) $event->app_id,
                $userIds,
                [
                    'type' => $announcement->level === 'critical' ? 'event_critical_announcement' : 'event_announcement',
                    'title' => $announcement->level === 'critical'
                        ? 'Aviso urgente · '.$event->title
                        : 'Aviso oficial · '.$event->title,
                    'message' => $announcement->title.': '.$announcement->message,
                    'reference_type' => 'event',
                    'reference_id' => $event->id,
                    'reference_url' => '/event/'.$event->slug.'#avisos-oficiais',
                    'data' => [
                        'event_id' => $event->id,
                        'announcement_id' => $announcement->id,
                        'level' => $announcement->level,
                        'audience' => $announcement->audience,
                    ],
                ],
                (int) $request->user()->id,
            );
            $notifiedCount = $sent->count();
            $announcement->forceFill(['notification_sent_at' => now()])->save();
        }

        return response()->json([
            'message' => $notifiedCount > 0
                ? "Aviso publicado e enviado para {$notifiedCount} usuário(s)."
                : 'Aviso publicado.',
            'announcement' => $announcement->fresh(),
            'notified_count' => $notifiedCount,
        ]);
    }

    public function unpublish(Request $request, int $eventId, int $announcementId)
    {
        $event = $this->ownedEvent($eventId, $request->user());
        $announcement = $this->announcement($event, $announcementId);
        $announcement->forceFill(['published_at' => null])->save();

        return response()->json([
            'message' => 'Aviso retirado da página pública. Notificações já enviadas não são removidas.',
            'announcement' => $announcement->fresh(),
        ]);
    }

    public function destroy(Request $request, int $eventId, int $announcementId)
    {
        $event = $this->ownedEvent($eventId, $request->user());
        $announcement = $this->announcement($event, $announcementId);
        $announcement->delete();

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

    private function ownedEvent(int $eventId, User $user): Event
    {
        $event = Event::query()
            ->where('app_id', $this->context->id())
            ->with('production')
            ->findOrFail($eventId);

        abort_unless($event->production && (int) $event->production->app_id === $this->context->id(), 404, 'Evento não encontrado neste contexto.');
        abort_unless(
            $user->hasProfile('Administrador') || (int) $event->production->user_id === (int) $user->id,
            403,
            'Você não pode gerenciar este evento.'
        );

        return $event;
    }

    private function announcement(Event $event, int $announcementId): EventAnnouncement
    {
        return EventAnnouncement::query()
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id)
            ->findOrFail($announcementId);
    }

    private function publicColumns(): array
    {
        return [
            'id', 'event_id', 'title', 'message', 'level', 'audience',
            'is_pinned', 'starts_at', 'ends_at', 'published_at', 'updated_at',
        ];
    }
}
