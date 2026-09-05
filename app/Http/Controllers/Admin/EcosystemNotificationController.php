<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

class EcosystemNotificationController extends Controller
{
    private const EXCLUSION_FIELDS = [
        'email',
        'user_name',
        'city',
        'uf',
        'profile_id',
        'application_role',
        'is_producer',
        'is_participant',
        'is_promoter',
        'is_partner',
        'newsletter_subscription',
        'email_verified',
    ];

    private const EXCLUSION_OPERATORS = [
        'equals',
        'contains',
        'starts_with',
        'ends_with',
        'is_true',
        'is_false',
    ];

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

        $baseAudience = $audience;
        $baseAudience['exclude_user_ids'] = [];
        $baseAudience['exclusion_rules'] = [];
        $baseCounts = $this->recipientCounts($this->recipientQuery($baseAudience));
        $counts = $this->recipientCounts($this->recipientQuery($audience));

        return response()->json([
            'audience' => $audience,
            'users_count' => $counts['users_count'],
            'deliveries_count' => $counts['deliveries_count'],
            'excluded_users_count' => max($baseCounts['users_count'] - $counts['users_count'], 0),
            'excluded_deliveries_count' => max($baseCounts['deliveries_count'] - $counts['deliveries_count'], 0),
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
        abort_if($counts['deliveries_count'] === 0, 422, 'Nenhum destinatário ativo foi encontrado para esse público depois das exclusões.');

        $campaignData = $content['data'] ?? [];
        $campaignData['audience_exclusions'] = [
            'user_ids' => $audience['exclude_user_ids'],
            'rules' => $audience['exclusion_rules'],
            'match' => $audience['exclusion_match'],
        ];

        $campaign = NotificationCampaign::query()->create([
            'created_by_user_id' => $request->user()->id,
            'app_id' => $audience['app_id'] ?? null,
            'audience_type' => $audience['audience_type'],
            'recipient_user_ids' => $audience['user_ids'] ?? null,
            'type' => $content['type'],
            'title' => trim($content['title']),
            'message' => trim($content['message']),
            'reference_url' => $content['reference_url'] ?? null,
            'data' => $campaignData,
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
                'excluded_user_ids' => $audience['exclude_user_ids'],
                'exclusion_rules_count' => count($audience['exclusion_rules']),
                'exclusion_match' => $audience['exclusion_match'],
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
            'exclude_user_ids' => ['nullable', 'array', 'max:500'],
            'exclude_user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'exclusion_match' => ['nullable', Rule::in(['any', 'all'])],
            'exclusion_rules' => ['nullable', 'array', 'max:20'],
            'exclusion_rules.*.field' => ['required', Rule::in(self::EXCLUSION_FIELDS)],
            'exclusion_rules.*.operator' => ['required', Rule::in(self::EXCLUSION_OPERATORS)],
            'exclusion_rules.*.value' => ['nullable'],
        ]);

        if ($data['audience_type'] === 'application' && empty($data['app_id'])) {
            abort(422, 'Selecione a aplicação que receberá a notificação.');
        }

        if ($data['audience_type'] === 'users' && empty($data['user_ids'])) {
            abort(422, 'Selecione pelo menos um usuário.');
        }

        $data['app_id'] = isset($data['app_id']) ? (int) $data['app_id'] : null;
        $data['user_ids'] = collect($data['user_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
        $data['exclude_user_ids'] = collect($data['exclude_user_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
        $data['exclusion_match'] = $data['exclusion_match'] ?? 'any';
        $data['exclusion_rules'] = collect($data['exclusion_rules'] ?? [])
            ->map(fn (array $rule) => $this->normalizeExclusionRule($rule))
            ->values()
            ->all();

        return $data;
    }

    private function normalizeExclusionRule(array $rule): array
    {
        $field = (string) ($rule['field'] ?? '');
        $operator = (string) ($rule['operator'] ?? '');
        $value = $rule['value'] ?? null;

        $textFields = ['email', 'user_name', 'city', 'application_role'];
        $booleanFields = ['is_producer', 'is_participant', 'is_promoter', 'is_partner', 'newsletter_subscription', 'email_verified'];

        if (in_array($field, $textFields, true)) {
            abort_unless(in_array($operator, ['equals', 'contains', 'starts_with', 'ends_with'], true), 422, 'Operador inválido para uma regra textual de exclusão.');
            $value = trim((string) $value);
            abort_if($value === '' || mb_strlen($value) > 180, 422, 'Informe um valor válido para a regra de exclusão.');
        } elseif ($field === 'uf') {
            abort_unless($operator === 'equals', 422, 'Estado (UF) aceita somente comparação exata.');
            $value = strtoupper(trim((string) $value));
            abort_unless((bool) preg_match('/^[A-Z]{2}$/', $value), 422, 'Informe uma UF válida para a regra de exclusão.');
        } elseif ($field === 'profile_id') {
            abort_unless($operator === 'equals', 422, 'Perfil aceita somente comparação exata.');
            $value = (int) $value;
            abort_unless($value > 0, 422, 'Informe um ID de perfil válido para a regra de exclusão.');
        } elseif (in_array($field, $booleanFields, true)) {
            abort_unless(in_array($operator, ['is_true', 'is_false'], true), 422, 'Operador inválido para uma regra booleana de exclusão.');
            $value = null;
        }

        return [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
        ];
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

        if (! empty($audience['exclude_user_ids'])) {
            $query->whereNotIn('application_user.user_id', $audience['exclude_user_ids']);
        }

        $this->applyExclusionRules($query, $audience);

        return $query;
    }

    private function applyExclusionRules(Builder $query, array $audience): void
    {
        $rules = $audience['exclusion_rules'] ?? [];
        if ($rules === []) {
            return;
        }

        $excludedUsers = DB::table('application_user as excluded_membership')
            ->join('applications as excluded_applications', 'excluded_applications.id', '=', 'excluded_membership.application_id')
            ->join('users as excluded_users', 'excluded_users.id', '=', 'excluded_membership.user_id')
            ->where('excluded_membership.status', 'active')
            ->where('excluded_applications.is_active', true)
            ->select('excluded_users.id')
            ->distinct();

        if (! empty($audience['app_id'])) {
            $excludedUsers->where('excluded_membership.application_id', $audience['app_id']);
        }

        $match = $audience['exclusion_match'] ?? 'any';
        $excludedUsers->where(function (Builder $conditions) use ($rules, $match) {
            foreach ($rules as $index => $rule) {
                $method = $match === 'any' && $index > 0 ? 'orWhere' : 'where';
                $conditions->{$method}(function (Builder $condition) use ($rule) {
                    $this->applyExclusionRule($condition, $rule);
                });
            }
        });

        $query->whereNotIn('application_user.user_id', $excludedUsers);
    }

    private function applyExclusionRule(Builder $query, array $rule): void
    {
        $field = $rule['field'];
        $operator = $rule['operator'];
        $value = $rule['value'];

        $columns = [
            'email' => 'excluded_users.email',
            'user_name' => 'excluded_users.user_name',
            'city' => 'excluded_users.city',
            'uf' => 'excluded_users.uf',
            'profile_id' => 'excluded_users.profile_id',
            'application_role' => 'excluded_membership.role',
            'is_producer' => 'excluded_users.is_producer',
            'is_participant' => 'excluded_users.is_participant',
            'is_promoter' => 'excluded_users.is_promoter',
            'is_partner' => 'excluded_users.is_partner',
            'newsletter_subscription' => 'excluded_users.newsletter_subscription',
        ];

        if ($field === 'email_verified') {
            $operator === 'is_true'
                ? $query->whereNotNull('excluded_users.email_verified_at')
                : $query->whereNull('excluded_users.email_verified_at');
            return;
        }

        $column = $columns[$field];
        if (in_array($operator, ['is_true', 'is_false'], true)) {
            $query->where($column, $operator === 'is_true');
            return;
        }

        if ($operator === 'contains') {
            $query->where($column, 'like', "%{$value}%");
            return;
        }
        if ($operator === 'starts_with') {
            $query->where($column, 'like', "{$value}%");
            return;
        }
        if ($operator === 'ends_with') {
            $query->where($column, 'like', "%{$value}");
            return;
        }

        $query->where($column, '=', $value);
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
        $campaignData = is_array($campaign->data) ? $campaign->data : [];

        return [
            'id' => $campaign->id,
            'audience_type' => $campaign->audience_type,
            'recipient_user_ids' => $campaign->recipient_user_ids,
            'audience_exclusions' => $campaignData['audience_exclusions'] ?? [
                'user_ids' => [],
                'rules' => [],
                'match' => 'any',
            ],
            'type' => $campaign->type,
            'title' => $campaign->title,
            'message' => $campaign->message,
            'reference_url' => $campaign->reference_url,
            'data' => $campaignData,
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
