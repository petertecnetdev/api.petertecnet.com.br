<?php

namespace App\Domain\Events\Services;

use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Services\EventAudienceService;
use Illuminate\Support\Carbon;

final class EventAnnouncementService
{
    public function __construct(
        private readonly EventAudienceService $audience,
        private readonly AppNotificationService $notifications,
    ) {}

    public function publicFeed(int $appId, string $slug): array
    {
        $event = Event::query()
            ->where('app_id', $appId)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($q) => $q->where('is_private', false)->orWhereNull('is_private'))
            ->firstOrFail();

        $announcements = EventAnnouncement::query()
            ->where('app_id', $appId)
            ->where('event_id', $event->id)
            ->visibleNow()
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(30)
            ->get([
                'id', 'event_id', 'title', 'message', 'level', 'audience',
                'is_pinned', 'starts_at', 'ends_at', 'published_at', 'updated_at',
            ]);

        return [
            'announcements' => $announcements,
            'meta' => [
                'event_id' => $event->id,
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    public function manageIndex(int $appId, User $user, int $eventId): array
    {
        $event = $this->ownedEvent($appId, $eventId, $user);

        return [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'slug' => $event->slug,
                'is_published' => (bool) $event->is_published,
                'is_cancelled' => (bool) $event->is_cancelled,
            ],
            'announcements' => EventAnnouncement::query()
                ->where('app_id', $appId)
                ->where('event_id', $event->id)
                ->orderByDesc('is_pinned')
                ->orderByDesc('created_at')
                ->get(),
        ];
    }

    public function create(int $appId, User $user, int $eventId, array $data): EventAnnouncement
    {
        $event = $this->ownedEvent($appId, $eventId, $user);

        return EventAnnouncement::create([
            ...$data,
            'app_id' => $appId,
            'event_id' => $event->id,
            'created_by' => $user->id,
            'published_at' => null,
            'notification_sent_at' => null,
        ]);
    }

    public function update(int $appId, User $user, int $eventId, int $announcementId, array $data): EventAnnouncement
    {
        $event = $this->ownedEvent($appId, $eventId, $user);
        $announcement = $this->announcement($appId, $event, $announcementId);
        $announcement->update($data);

        return $announcement->fresh();
    }

    public function publish(int $appId, User $user, int $eventId, int $announcementId): array
    {
        $event = $this->ownedEvent($appId, $eventId, $user);
        abort_if($event->is_cancelled, 422, 'Não é possível publicar avisos em um evento cancelado.');
        abort_unless($event->is_published, 422, 'Publique o evento antes de publicar um aviso oficial.');

        $announcement = $this->announcement($appId, $event, $announcementId);
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
                $appId,
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
                (int) $user->id,
            );
            $notifiedCount = $sent->count();
            $announcement->forceFill(['notification_sent_at' => now()])->save();
        }

        return [
            'announcement' => $announcement->fresh(),
            'notified_count' => $notifiedCount,
        ];
    }

    public function unpublish(int $appId, User $user, int $eventId, int $announcementId): EventAnnouncement
    {
        $event = $this->ownedEvent($appId, $eventId, $user);
        $announcement = $this->announcement($appId, $event, $announcementId);
        $announcement->forceFill(['published_at' => null])->save();

        return $announcement->fresh();
    }

    public function delete(int $appId, User $user, int $eventId, int $announcementId): void
    {
        $event = $this->ownedEvent($appId, $eventId, $user);
        $this->announcement($appId, $event, $announcementId)->delete();
    }

    private function ownedEvent(int $appId, int $eventId, User $user): Event
    {
        $event = Event::query()
            ->where('app_id', $appId)
            ->with('production')
            ->findOrFail($eventId);

        abort_unless($event->production && (int) $event->production->app_id === $appId, 404, 'Evento não encontrado neste contexto.');
        abort_unless(
            $user->hasProfile('Administrador') || (int) $event->production->user_id === (int) $user->id,
            403,
            'Você não pode gerenciar este evento.'
        );

        return $event;
    }

    private function announcement(int $appId, Event $event, int $announcementId): EventAnnouncement
    {
        return EventAnnouncement::query()
            ->where('app_id', $appId)
            ->where('event_id', $event->id)
            ->findOrFail($announcementId);
    }
}
