<?php

namespace App\Services\Admin;

use App\Models\AppNotification;
use App\Models\Application;
use App\Models\EcosystemAuditLog;
use App\Models\NotificationCampaign;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class EcosystemNotificationService
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'type' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['sending', 'sent', 'failed'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = NotificationCampaign::query()
            ->with([
                'application:id,name,slug,logo',
                'creator:id,first_name,last_name,email',
            ])
            ->withCount([
                'notifications as delivered_count',
                'notifications as read_count' => fn ($notifications) => $notifications->whereNotNull('read_at'),
            ]);

        if (! empty($data['app_id'])) {
            $query->where('app_id', $data['app_id']);
        }
        if (! empty($data['type'])) {
            $query->where('type', $data['type']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if ($search = trim((string) ($data['search'] ?? ''))) {
            $query->where(function ($campaign) use ($search) {
                $campaign->where('title', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%")
                    ->orWhere('id', $search);
            });
        }

        $perPage = (int) ($data['per_page'] ?? 25);
        $campaigns = $query->latest('id')->paginate($perPage)->appends($request->query());

        $campaigns->getCollection()->transform(fn (NotificationCampaign $campaign) => $this->campaignPayload($campaign));

        $campaignNotificationQuery = AppNotification::query()->whereNotNull('campaign_id');

        return response()->json([
            'summary' => [
                'campaigns' => NotificationCampaign::query()->count(),
                'campaigns_sent' => NotificationCampaign::query()->where('status', 'sent')->count(),
                'deliveries' => (clone $campaignNotificationQuery)->count(),
                'read' => (clone $campaignNotificationQuery)->whereNotNull('read_at')->count(),
                'unread' => (clone $campaignNotificationQuery)->whereNull('read_at')->count(),
            ],
            'campaigns' => $campaigns,
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $audience = $this->validateAudience($request);
        $counts = $this->recipientCounts($this->recipientQuery($audience));

        return response()->json([
            'audience' => $audience,
            'users_count' => $counts['users_count'],
            'deliveries_count' => $counts['deliveries_count'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        $content = $request->validate([
            'type' => ['required', Rule::in(['general', 'info', 'success', 'warning', 'critical', 'maintenance', 'marketing'])],
            'title' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:5000'],
            'reference_url' => ['nullable', 'string', 'max:500'],
            'data' => ['nullable', 'array'],
        ]);
        $audience = $this->validateAudience($request);
        $this->validateReferenceUrl($content['reference_url'] ?? null);

        $targetQuery = $this->recipientQuery($audience);
        $counts = $this->recipientCounts(clone $targetQuery);
        abort_if($counts['deliveries_count'] === 0, 422, 'Nenhum destinatário ativo foi encontrado para esse público.');

        $campaign = NotificationCampaign::query()->create([
            'created_by_user_id' => $request->user()->id,
            'app_id' => $audience['app_id'] ?? null,
            'audience_type' => $audience['audience_type'],
            'recipient_user_ids' => $audience['user_ids'] ?? null,
            'type' => $content['type'],
            'title' => trim($content['title']),
            'message' => trim($content['message']),
            'reference_url' => $content['reference_url'] ?? null,
            'data' => $content['data'] ?? null,
            'status' => 'sending',
        ]);

        try {
            $delivered = DB::transaction(function () use ($campaign, $targetQuery, $content, $audience) {
                $delivered = 0;
                $createdAt = now();
                $notificationData = json_encode([
                    'source' => 'admin_center',
                    'campaign_id' => $campaign->id,
                    'audience_type' => $audience['audience_type'],
                    'campaign_data' => $content['data'] ?? [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $targetQuery
                    ->select([
                        'application_user.user_id',
                        'application_user.application_id as app_id',
                    ])
                    ->distinct()
                    ->orderBy('application_user.user_id')
                    ->orderBy('application_user.application_id')
                    ->chunk(1000, function ($targets) use (&$delivered, $campaign, $content, $createdAt, $notificationData) {
                        $rows = $targets->map(fn ($target) => [
                            'app_id' => (int) $target->app_id,
                            'user_id' => (int) $target->user_id,
                            'campaign_id' => $campaign->id,
                            'type' => $content['type'],
                            'title' => trim($content['title']),
                            'message' => trim($content['message']),
                            'reference_type' => 'notification_campaign',
                            'reference_id' => $campaign->id,
                            'reference_url' => $content['reference_url'] ?? null,
                            'data' => $notificationData,
                            'read_at' => null,
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ])->all();

                        if ($rows !== []) {
                            DB::table('app_notifications')->insert($rows);
                            $delivered += count($rows);
                        }
                    });

                $campaign->forceFill([
                    'status' => 'sent',
                    'recipients_count' => $delivered,
                    'sent_at' => now(),
                ])->save();

                return $delivered;
            });
        } catch (Throwable $exception) {
            $campaign->forceFill([
                'status' => 'failed',
                'data' => array_merge($campaign->data ?? [], [
                    'failure' => Str::limit($exception->getMessage(), 800, ''),
                ]),
            ])->save();

            throw $exception;
        }

        EcosystemAuditLog::query()->create([
            'user_id' => $request->user()->id,
            'action' => 'notification_campaign.sent',
            'entity_type' => NotificationCampaign::class,
            'entity_id' => $campaign->id,
            'before' => null,
            'after' => [
                'audience_type' => $campaign->audience_type,
                'app_id' => $campaign->app_id,
                'recipients_count' => $delivered,
                'type' => $campaign->type,
                'title' => $campaign->title,
            ],
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);

        $campaign->load([
            'application:id,name,slug,logo',
            'creator:id,first_name,last_name,email',
        ])->loadCount([
            'notifications as delivered_count',
            'notifications as read_count' => fn ($notifications) => $notifications->whereNotNull('read_at'),
        ]);

        return response()->json([
            'message' => 'Notificação enviada com sucesso.',
            'campaign' => $this->campaignPayload($campaign),
            'users_count' => $counts['users_count'],
            'deliveries_count' => $delivered,
        ], 201);
    }

    private function validateAudience(Request $request): array
    {
        $data = $request->validate([
            'audience_type' => ['required', Rule::in(['ecosystem', 'application', 'users'])],
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'user_ids' => ['nullable', 'array', 'max:500'],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        if ($data['audience_type'] === 'application' && empty($data['app_id'])) {
            abort(422, 'Selecione a aplicação que receberá a notificação.');
        }

        if ($data['audience_type'] === 'users' && empty($data['user_ids'])) {
            abort(422, 'Selecione pelo menos um usuário.');
        }

        $data['app_id'] = isset($data['app_id']) ? (int) $data['app_id'] : null;
        $data['user_ids'] = collect($data['user_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

        return $data;
    }

    private function recipientQuery(array $audience): Builder
    {
        $query = DB::table('application_user')
            ->join('applications', 'applications.id', '=', 'application_user.application_id')
            ->join('users', 'users.id', '=', 'application_user.user_id')
            ->where('application_user.status', 'active')
            ->where('applications.is_active', true);

        if (! empty($audience['app_id'])) {
            $query->where('application_user.application_id', $audience['app_id']);
        }

        if ($audience['audience_type'] === 'users') {
            $query->whereIn('application_user.user_id', $audience['user_ids']);
        }

        return $query;
    }

    private function recipientCounts(Builder $query): array
    {
        $deliveriesQuery = (clone $query)
            ->select([
                'application_user.user_id',
                'application_user.application_id',
            ])
            ->distinct();

        $usersQuery = (clone $query)
            ->select('application_user.user_id')
            ->distinct();

        return [
            'users_count' => DB::query()->fromSub($usersQuery, 'notification_users')->count(),
            'deliveries_count' => DB::query()->fromSub($deliveriesQuery, 'notification_deliveries')->count(),
        ];
    }

    private function validateReferenceUrl(?string $url): void
    {
        if ($url === null || trim($url) === '') {
            return;
        }

        $url = trim($url);
        if (Str::startsWith($url, '/')) {
            return;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        abort_unless(filter_var($url, FILTER_VALIDATE_URL) && $scheme === 'https', 422, 'O link da notificação deve ser relativo ou usar HTTPS.');
    }

    private function campaignPayload(NotificationCampaign $campaign): array
    {
        $delivered = (int) ($campaign->delivered_count ?? $campaign->recipients_count ?? 0);
        $read = (int) ($campaign->read_count ?? 0);

        return [
            'id' => $campaign->id,
            'audience_type' => $campaign->audience_type,
            'recipient_user_ids' => $campaign->recipient_user_ids,
            'type' => $campaign->type,
            'title' => $campaign->title,
            'message' => $campaign->message,
            'reference_url' => $campaign->reference_url,
            'data' => $campaign->data,
            'status' => $campaign->status,
            'recipients_count' => $delivered,
            'read_count' => $read,
            'unread_count' => max($delivered - $read, 0),
            'read_rate' => $delivered > 0 ? round(($read / $delivered) * 100, 1) : 0,
            'sent_at' => $campaign->sent_at,
            'created_at' => $campaign->created_at,
            'application' => $campaign->application,
            'creator' => $campaign->creator,
        ];
    }

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        $ownerEmail = strtolower((string) config('app.admin_center_owner_email', 'petertecnet@gmail.com'));

        abort_unless($user && strtolower((string) $user->email) === $ownerEmail, 403, 'Somente o proprietário do Admin Center pode enviar notificações do ecossistema.');
    }
}
