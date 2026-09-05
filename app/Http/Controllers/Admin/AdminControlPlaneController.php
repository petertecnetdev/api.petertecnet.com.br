<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchNotificationCampaign;
use App\Models\EcosystemSetting;
use App\Models\NotificationCampaign;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminControlPlaneController extends Controller
{
    private const EXPORTABLE = [
        'users' => ['id', 'user_name', 'first_name', 'last_name', 'email', 'phone', 'city', 'uf', 'profile_id', 'email_verified_at', 'created_at'],
        'applications' => ['id', 'name', 'slug', 'url', 'is_active', 'created_at', 'updated_at'],
        'establishments' => ['id', 'user_id', 'app_id', 'name', 'fantasy', 'slug', 'cnpj', 'type', 'category', 'email', 'phone', 'city', 'uf', 'is_approved', 'is_published', 'created_at', 'updated_at'],
        'items' => ['id', 'user_id', 'app_id', 'name', 'slug', 'type', 'sku', 'price', 'stock', 'status', 'category', 'created_at', 'updated_at'],
    ];

    private const IMPORTABLE = [
        'establishments' => ['user_id', 'app_id', 'name', 'fantasy', 'slug', 'cnpj', 'type', 'category', 'email', 'phone', 'city', 'uf', 'description'],
        'items' => ['user_id', 'app_id', 'name', 'slug', 'type', 'sku', 'description', 'duration', 'price', 'stock', 'status', 'category', 'subcategory', 'brand'],
    ];

    public function capabilities()
    {
        return response()->json([
            'notifications' => [
                'channels' => ['in_app', 'email'],
                'push' => ['configured' => false, 'reason' => 'Nenhum provedor/subscription Web Push está configurado na API central.'],
                'scheduling' => true,
                'audiences' => ['all', 'application', 'users', 'profile', 'establishment'],
            ],
            'imports' => array_keys(self::IMPORTABLE),
            'exports' => array_keys(self::EXPORTABLE),
            'feature_flags' => true,
            'saved_views' => true,
            'trash_restore' => true,
            'moderation' => Schema::hasTable('laora_reports'),
        ]);
    }

    public function featureFlags()
    {
        $flags = EcosystemSetting::query()
            ->where('group', 'feature_flags')
            ->orderBy('key')
            ->get(['id', 'key', 'value', 'updated_by', 'updated_at'])
            ->map(fn ($row) => ['key' => $row->key, ...((array) ($row->value ?: [])), 'updated_at' => $row->updated_at]);

        return response()->json(['flags' => $flags]);
    }

    public function saveFeatureFlags(Request $request)
    {
        $validated = $request->validate([
            'flags' => ['required', 'array', 'max:100'],
            'flags.*.key' => ['required', 'string', 'max:150', 'regex:/^[a-z0-9._-]+$/'],
            'flags.*.enabled' => ['required', 'boolean'],
            'flags.*.description' => ['nullable', 'string', 'max:500'],
            'flags.*.environment' => ['nullable', Rule::in(['all', 'production', 'staging', 'development'])],
            'flags.*.application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'flags.*.user_ids' => ['nullable', 'array', 'max:500'],
            'flags.*.user_ids.*' => ['integer', 'exists:users,id'],
            'flags.*.establishment_ids' => ['nullable', 'array', 'max:500'],
            'flags.*.establishment_ids.*' => ['integer', 'exists:establishments,id'],
        ]);

        $keys = [];
        foreach ($validated['flags'] as $flag) {
            $keys[] = $flag['key'];
            EcosystemSetting::updateOrCreate(
                ['group' => 'feature_flags', 'key' => $flag['key']],
                [
                    'value' => Arr::except($flag, ['key']),
                    'is_public' => false,
                    'updated_by' => $request->user()->id,
                ]
            );
        }

        $this->audit($request, 'feature_flags.update', 'feature_flags', null, ['keys' => $keys]);
        return $this->featureFlags();
    }

    public function savedViews(Request $request)
    {
        $query = EcosystemSetting::query()->where('group', 'admin_saved_views')->orderBy('key');
        if ($request->filled('section')) {
            $query->where('key', 'like', Str::slug($request->string('section')) . '.%');
        }

        return response()->json([
            'views' => $query->get(['id', 'key', 'value', 'updated_at'])->map(fn ($row) => [
                'id' => $row->id,
                'key' => $row->key,
                ...((array) ($row->value ?: [])),
                'updated_at' => $row->updated_at,
            ]),
        ]);
    }

    public function saveView(Request $request)
    {
        $validated = $request->validate([
            'section' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:120'],
            'filters' => ['required', 'array'],
        ]);
        $section = Str::slug($validated['section']);
        $viewKey = $section . '.' . Str::slug($validated['name']) . '-' . substr(sha1(json_encode($validated['filters'])), 0, 8);
        $view = EcosystemSetting::updateOrCreate(
            ['group' => 'admin_saved_views', 'key' => $viewKey],
            ['value' => $validated, 'is_public' => false, 'updated_by' => $request->user()->id]
        );
        $this->audit($request, 'saved_view.save', 'ecosystem_setting', $view->id, $validated);
        return response()->json(['view' => ['id' => $view->id, 'key' => $view->key, ...$validated]], 201);
    }

    public function deleteView(Request $request, EcosystemSetting $setting)
    {
        abort_unless($setting->group === 'admin_saved_views', 404);
        $before = $setting->toArray();
        $setting->delete();
        $this->audit($request, 'saved_view.delete', 'ecosystem_setting', $setting->id, $before);
        return response()->noContent();
    }

    public function notificationCampaigns(Request $request)
    {
        $query = NotificationCampaign::query()->with(['application:id,name,slug', 'creator:id,first_name,last_name,email'])->latest();
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        if ($request->filled('app_id')) $query->where('app_id', $request->integer('app_id'));
        if ($request->filled('q')) {
            $term = '%' . $request->string('q') . '%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('message', 'like', $term));
        }

        $page = $query->paginate(min(max($request->integer('per_page', 25), 1), 100));
        return response()->json([
            'campaigns' => $page,
            'summary' => [
                'total' => NotificationCampaign::count(),
                'scheduled' => NotificationCampaign::where('status', 'scheduled')->count(),
                'completed' => NotificationCampaign::where('status', 'completed')->count(),
                'failed' => NotificationCampaign::where('status', 'failed')->count(),
                'recipients' => (int) NotificationCampaign::sum('recipients_count'),
                'delivered' => (int) NotificationCampaign::sum('delivered_count'),
            ],
        ]);
    }

    public function storeNotificationCampaign(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'message' => ['nullable', 'string', 'max:10000'],
            'type' => ['nullable', 'string', 'max:80'],
            'reference_url' => ['nullable', 'url', 'max:500'],
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'audience_type' => ['required', Rule::in(['all', 'application', 'users', 'profile', 'establishment'])],
            'recipient_user_ids' => ['nullable', 'array', 'max:5000'],
            'recipient_user_ids.*' => ['integer', 'exists:users,id'],
            'profile_id' => ['nullable', 'integer', 'exists:profiles,id'],
            'establishment_id' => ['nullable', 'integer', 'exists:establishments,id'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', Rule::in(['in_app', 'email'])],
            'scheduled_at' => ['nullable', 'date'],
            'data' => ['nullable', 'array'],
        ]);

        if ($validated['audience_type'] === 'application' && empty($validated['app_id'])) {
            return response()->json(['message' => 'Selecione a aplicação para esta audiência.'], 422);
        }

        $recipientIds = $this->resolveRecipients($validated);
        if ($recipientIds->isEmpty()) {
            return response()->json(['message' => 'Nenhum usuário corresponde à audiência selecionada.'], 422);
        }

        $scheduledAt = ! empty($validated['scheduled_at']) ? Carbon::parse($validated['scheduled_at']) : null;
        $isScheduled = $scheduledAt && $scheduledAt->isFuture();
        $data = $validated['data'] ?? [];
        $data['audience'] = Arr::only($validated, ['profile_id', 'establishment_id']);

        $campaign = NotificationCampaign::create([
            'created_by_user_id' => $request->user()->id,
            'app_id' => $validated['app_id'] ?? null,
            'audience_type' => $validated['audience_type'],
            'recipient_user_ids' => $recipientIds->values()->all(),
            'type' => $validated['type'] ?? 'general',
            'title' => $validated['title'],
            'message' => $validated['message'] ?? null,
            'reference_url' => $validated['reference_url'] ?? null,
            'data' => $data,
            'channels' => array_values(array_unique($validated['channels'])),
            'status' => $isScheduled ? 'scheduled' : 'queued',
            'scheduled_at' => $scheduledAt,
            'recipients_count' => $recipientIds->count(),
        ]);

        $dispatch = DispatchNotificationCampaign::dispatch($campaign->id);
        if ($isScheduled) $dispatch->delay($scheduledAt);

        $this->audit($request, 'notification_campaign.create', 'notification_campaign', $campaign->id, [
            'audience_type' => $campaign->audience_type,
            'recipients_count' => $campaign->recipients_count,
            'channels' => $campaign->channels,
            'scheduled_at' => $campaign->scheduled_at,
        ]);

        return response()->json(['campaign' => $campaign->fresh(['application', 'creator'])], 201);
    }

    public function moderation(Request $request)
    {
        if (! Schema::hasTable('laora_reports')) {
            return response()->json(['reports' => ['data' => []], 'summary' => ['open' => 0, 'resolved' => 0]]);
        }

        $query = DB::table('laora_reports as r')
            ->leftJoin('users as reporter', 'reporter.id', '=', 'r.reporter_user_id')
            ->leftJoin('users as reported', 'reported.id', '=', 'r.reported_user_id')
            ->select(['r.*', 'reporter.email as reporter_email', 'reported.email as reported_email'])
            ->orderByDesc('r.created_at');
        if ($request->filled('status')) $query->where('r.status', $request->string('status'));
        if ($request->filled('q')) {
            $term = '%' . $request->string('q') . '%';
            $query->where(fn ($q) => $q->where('r.reason', 'like', $term)->orWhere('r.details', 'like', $term)->orWhere('reported.email', 'like', $term));
        }

        return response()->json([
            'reports' => $query->paginate(min(max($request->integer('per_page', 25), 1), 100)),
            'summary' => [
                'open' => DB::table('laora_reports')->where('status', 'open')->count(),
                'resolved' => DB::table('laora_reports')->where('status', 'resolved')->count(),
                'dismissed' => DB::table('laora_reports')->where('status', 'dismissed')->count(),
                'actions' => Schema::hasTable('laora_moderation_actions') ? DB::table('laora_moderation_actions')->count() : 0,
            ],
        ]);
    }

    public function updateModeration(Request $request, int $report)
    {
        abort_unless(Schema::hasTable('laora_reports'), 404);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'resolved', 'dismissed'])],
            'action' => ['nullable', Rule::in(['none', 'warn', 'suspend', 'ban'])],
            'reason' => ['nullable', 'string', 'max:160'],
            'expires_at' => ['nullable', 'date'],
        ]);
        $row = DB::table('laora_reports')->where('id', $report)->first();
        abort_unless($row, 404);

        DB::transaction(function () use ($request, $report, $row, $validated) {
            DB::table('laora_reports')->where('id', $report)->update([
                'status' => $validated['status'],
                'resolved_at' => $validated['status'] === 'open' ? null : now(),
                'resolved_by_user_id' => $validated['status'] === 'open' ? null : $request->user()->id,
                'updated_at' => now(),
            ]);
            $action = $validated['action'] ?? 'none';
            if ($action !== 'none' && Schema::hasTable('laora_moderation_actions')) {
                DB::table('laora_moderation_actions')->insert([
                    'report_id' => $report,
                    'target_user_id' => $row->reported_user_id,
                    'moderator_user_id' => $request->user()->id,
                    'action' => $action,
                    'reason' => $validated['reason'] ?? $row->reason,
                    'expires_at' => $validated['expires_at'] ?? null,
                    'metadata' => json_encode(['source' => 'admin_center']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if (in_array($action, ['suspend', 'ban'], true) && Schema::hasTable('laora_profiles')) {
                    DB::table('laora_profiles')->where('user_id', $row->reported_user_id)->update(['discovery_enabled' => false, 'updated_at' => now()]);
                }
            }
        });

        $this->audit($request, 'moderation.update', 'laora_report', $report, $validated);
        return response()->json(['message' => 'Moderação atualizada com sucesso.']);
    }

    public function trash(Request $request)
    {
        $resources = [
            'establishments' => ['label' => 'Estabelecimentos', 'name' => 'COALESCE(fantasy, name)'],
            'productions' => ['label' => 'Produções legadas', 'name' => 'name'],
            'items' => ['label' => 'Itens', 'name' => 'name'],
            'laora_messages' => ['label' => 'Mensagens removidas', 'name' => "CONCAT('Mensagem #', id)"],
        ];
        $rows = collect();
        foreach ($resources as $table => $meta) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) continue;
            $query = DB::table($table)->whereNotNull('deleted_at')->select(['id', 'deleted_at', DB::raw($meta['name'] . ' as display_name')]);
            if ($request->filled('q')) {
                $term = '%' . $request->string('q') . '%';
                if (Schema::hasColumn($table, 'name')) $query->where('name', 'like', $term);
            }
            $rows = $rows->concat($query->orderByDesc('deleted_at')->limit(100)->get()->map(fn ($row) => [
                'resource' => $table,
                'resource_label' => $meta['label'],
                'id' => $row->id,
                'name' => $row->display_name,
                'deleted_at' => $row->deleted_at,
            ]));
        }

        return response()->json(['items' => $rows->sortByDesc('deleted_at')->values()->take(200), 'total' => $rows->count()]);
    }

    public function restoreTrash(Request $request, string $resource, int $id)
    {
        $allowed = ['establishments', 'productions', 'items', 'laora_messages'];
        abort_unless(in_array($resource, $allowed, true) && Schema::hasTable($resource) && Schema::hasColumn($resource, 'deleted_at'), 404);
        $updated = DB::table($resource)->where('id', $id)->whereNotNull('deleted_at')->update(['deleted_at' => null, 'updated_at' => now()]);
        abort_unless($updated, 404);
        $this->audit($request, 'trash.restore', $resource, $id, ['restored' => true]);
        return response()->json(['message' => 'Registro restaurado com sucesso.']);
    }

    public function export(Request $request, string $resource)
    {
        abort_unless(isset(self::EXPORTABLE[$resource]) && Schema::hasTable($resource), 404);
        $columns = array_values(array_filter(self::EXPORTABLE[$resource], fn ($column) => Schema::hasColumn($resource, $column)));
        $filename = 'petertecnet-' . $resource . '-' . now()->format('Ymd-His') . '.csv';
        $this->audit($request, 'export.csv', $resource, null, ['columns' => $columns]);

        return response()->streamDownload(function () use ($resource, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ';');
            DB::table($resource)->select($columns)->orderBy('id')->chunk(500, function ($rows) use ($out, $columns) {
                foreach ($rows as $row) fputcsv($out, array_map(fn ($column) => data_get($row, $column), $columns), ';');
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request, string $resource)
    {
        abort_unless(isset(self::IMPORTABLE[$resource]) && Schema::hasTable($resource), 404);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt']]);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        abort_unless($handle, 422, 'Não foi possível ler o arquivo.');
        $header = fgetcsv($handle, 0, ';');
        if (! $header || count($header) === 1) {
            rewind($handle);
            $header = fgetcsv($handle, 0, ',');
            $delimiter = ',';
        } else {
            $delimiter = ';';
        }
        $header = array_map(fn ($value) => trim((string) $value, "\xEF\xBB\xBF \t\n\r\0\x0B"), $header ?: []);
        $allowed = self::IMPORTABLE[$resource];
        $rows = [];
        $errors = [];
        $line = 1;
        while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;
            if (count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) continue;
            if (count($values) !== count($header)) { $errors[] = "Linha {$line}: quantidade de colunas inválida."; continue; }
            $data = array_intersect_key(array_combine($header, $values), array_flip($allowed));
            $data = array_map(fn ($value) => $value === '' ? null : $value, $data);
            if (empty($data['name'])) { $errors[] = "Linha {$line}: name é obrigatório."; continue; }
            $data['created_at'] = now();
            $data['updated_at'] = now();
            $rows[] = $data;
            if (count($rows) >= 1000) break;
        }
        fclose($handle);
        if ($errors) return response()->json(['message' => 'O CSV possui erros.', 'errors' => $errors], 422);

        DB::transaction(function () use ($resource, $rows) {
            foreach ($rows as $row) {
                $key = ! empty($row['slug']) ? ['slug' => $row['slug']] : ['name' => $row['name'], 'app_id' => $row['app_id'] ?? null];
                DB::table($resource)->updateOrInsert($key, $row);
            }
        });
        $this->audit($request, 'import.csv', $resource, null, ['rows' => count($rows)]);
        return response()->json(['message' => 'Importação concluída.', 'rows' => count($rows)]);
    }

    private function resolveRecipients(array $input)
    {
        $type = $input['audience_type'];
        if ($type === 'users') return collect($input['recipient_user_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();

        if ($type === 'profile') {
            return User::query()->where('profile_id', $input['profile_id'] ?? 0)->pluck('id');
        }

        if ($type === 'establishment') {
            $establishmentId = (int) ($input['establishment_id'] ?? 0);
            $ids = collect(DB::table('establishments')->where('id', $establishmentId)->pluck('user_id'));
            if (Schema::hasTable('employers') && Schema::hasColumn('employers', 'establishment_id')) {
                $ids = $ids->merge(DB::table('employers')->where('establishment_id', $establishmentId)->pluck('user_id'));
            }
            return $ids->map(fn ($id) => (int) $id)->filter()->unique();
        }

        $query = DB::table('application_user')->where('status', 'active');
        if (! empty($input['app_id'])) $query->where('application_id', (int) $input['app_id']);
        return $query->pluck('user_id')->map(fn ($id) => (int) $id)->filter()->unique();
    }

    private function audit(Request $request, string $action, ?string $entityType, ?int $entityId, array $after = []): void
    {
        if (! Schema::hasTable('ecosystem_audit_logs')) return;
        DB::table('ecosystem_audit_logs')->insert([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => null,
            'after' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
