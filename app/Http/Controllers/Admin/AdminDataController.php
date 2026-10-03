<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcosystemAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminDataController extends Controller
{
    private const BLOCKED_TABLES = [
        'migrations',
        'password_reset_tokens',
        'personal_access_tokens',
        'oauth_access_tokens',
        'oauth_auth_codes',
        'oauth_clients',
        'oauth_device_codes',
        'oauth_personal_access_clients',
        'oauth_refresh_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    private const READ_ONLY_TABLES = [
        'ecosystem_audit_logs',
        'interactions',
    ];

    private const SENSITIVE_COLUMNS = [
        'password',
        'remember_token',
        'access_token',
        'refresh_token',
        'api_token',
        'token',
        'secret',
        'client_secret',
        'private_key',
        'recovery_codes',
        'two_factor_secret',
    ];

    private const SYSTEM_COLUMNS = [
        'created_at',
        'updated_at',
        'deleted_at',
        'created_by',
        'updated_by',
    ];

    public function tables(Request $request): JsonResponse
    {
        $tables = collect(Schema::getTableListing())
            ->map(fn ($table) => (string) $table)
            ->filter(fn ($table) => $this->isTableVisible($table))
            ->sort()
            ->values()
            ->map(fn ($table) => $this->describeTable($table));

        return response()->json([
            'tables' => $tables,
            'policy' => [
                'sensitive_fields_hidden' => true,
                'system_fields_read_only' => true,
                'audit_tables_read_only' => true,
            ],
        ]);
    }

    public function index(Request $request, string $table): JsonResponse
    {
        $this->assertTable($table);
        $meta = $this->describeTable($table);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $visibleColumns = collect($meta['columns'])
            ->where('visible', true)
            ->pluck('name')
            ->values()
            ->all();

        abort_if(empty($visibleColumns), 422, 'Esta tabela não possui colunas administrativas visíveis.');

        $query = DB::table($table)->select($visibleColumns);
        $search = trim((string) ($data['q'] ?? ''));
        if ($search !== '') {
            $searchable = collect($meta['columns'])
                ->where('visible', true)
                ->filter(fn ($column) => $this->isSearchableType((string) ($column['type'] ?? '')))
                ->pluck('name')
                ->values();

            if ($searchable->isNotEmpty()) {
                $query->where(function ($where) use ($searchable, $search) {
                    foreach ($searchable as $index => $column) {
                        $method = $index === 0 ? 'where' : 'orWhere';
                        $where->{$method}($column, 'like', '%' . $search . '%');
                    }
                });
            }
        }

        foreach ($meta['key_columns'] as $column) {
            $query->orderBy($column);
        }
        if (empty($meta['key_columns']) && ! empty($visibleColumns[0])) {
            $query->orderBy($visibleColumns[0]);
        }

        $paginator = $query->paginate((int) ($data['per_page'] ?? 30));
        $rows = collect($paginator->items())->map(function ($row) use ($meta) {
            $array = (array) $row;
            $array['_admin_key'] = $this->encodeKey($array, $meta['key_columns']);
            return $array;
        })->values();

        return response()->json([
            'table' => $meta,
            'rows' => $rows,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    public function show(Request $request, string $table, string $key): JsonResponse
    {
        $this->assertTable($table);
        $meta = $this->describeTable($table);
        $where = $this->decodeKey($key, $meta['key_columns']);
        $row = $this->findRow($table, $where, $meta);

        return response()->json([
            'table' => $meta,
            'row' => $row,
        ]);
    }

    public function update(Request $request, string $table, string $key): JsonResponse
    {
        $this->assertTable($table);
        $meta = $this->describeTable($table);
        abort_unless($meta['editable'], 422, 'Esta tabela é somente leitura no Admin Center.');

        $where = $this->decodeKey($key, $meta['key_columns']);
        $payload = $request->validate([
            'data' => ['required', 'array', 'min:1'],
        ]);

        $columns = collect($meta['columns'])->keyBy('name');
        $changes = [];
        foreach ($payload['data'] as $name => $value) {
            $column = $columns->get($name);
            if (! $column || ! $column['editable']) {
                continue;
            }
            $changes[$name] = $this->normalizeValue($value, $column);
        }

        abort_if(empty($changes), 422, 'Nenhum campo editável foi enviado.');

        $beforeObject = $this->baseQuery($table, $where)->first();
        abort_unless($beforeObject, 404, 'Registro não encontrado.');
        $before = (array) $beforeObject;

        if (Schema::hasColumn($table, 'updated_at') && ! array_key_exists('updated_at', $changes)) {
            $changes['updated_at'] = now();
        }
        if (Schema::hasColumn($table, 'updated_by') && ! array_key_exists('updated_by', $changes)) {
            $changes['updated_by'] = $request->user()->id;
        }

        DB::transaction(function () use ($table, $where, $changes) {
            $this->baseQuery($table, $where)->update($changes);
        });

        $fresh = $this->findRow($table, $where, $meta);
        $this->audit($request, $table, $where, $before, $fresh);

        return response()->json([
            'message' => 'Registro atualizado com sucesso.',
            'table' => $meta,
            'row' => $fresh,
        ]);
    }

    private function describeTable(string $table): array
    {
        $columns = collect(Schema::getColumns($table));
        $indexes = collect(Schema::getIndexes($table));
        $primary = $indexes->first(fn ($index) => (bool) ($index['primary'] ?? false));
        $unique = $indexes->first(fn ($index) => (bool) ($index['unique'] ?? false));
        $keyColumns = array_values($primary['columns'] ?? $unique['columns'] ?? []);
        $tableEditable = ! in_array($table, self::READ_ONLY_TABLES, true) && ! empty($keyColumns);

        $normalizedColumns = $columns->map(function ($column) use ($keyColumns, $tableEditable) {
            $name = (string) ($column['name'] ?? '');
            $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? 'string'));
            $sensitive = $this->isSensitiveColumn($name);
            $system = in_array($name, self::SYSTEM_COLUMNS, true);
            $key = in_array($name, $keyColumns, true);

            return [
                'name' => $name,
                'type' => $type,
                'nullable' => (bool) ($column['nullable'] ?? true),
                'default' => $column['default'] ?? null,
                'key' => $key,
                'system' => $system,
                'sensitive' => $sensitive,
                'visible' => ! $sensitive,
                'editable' => $tableEditable && ! $sensitive && ! $system && ! $key,
            ];
        })->values()->all();

        return [
            'name' => $table,
            'label' => Str::headline($table),
            'editable' => $tableEditable,
            'key_columns' => $keyColumns,
            'columns' => $normalizedColumns,
        ];
    }

    private function assertTable(string $table): void
    {
        abort_unless((bool) preg_match('/^[A-Za-z0-9_]+$/', $table), 404);
        abort_unless($this->isTableVisible($table) && Schema::hasTable($table), 404, 'Tabela administrativa não encontrada.');
    }

    private function isTableVisible(string $table): bool
    {
        $plain = str_contains($table, '.') ? Str::afterLast($table, '.') : $table;
        return (bool) preg_match('/^[A-Za-z0-9_]+$/', $plain)
            && ! in_array($plain, self::BLOCKED_TABLES, true);
    }

    private function isSensitiveColumn(string $name): bool
    {
        $lower = strtolower($name);
        foreach (self::SENSITIVE_COLUMNS as $sensitive) {
            if ($lower === $sensitive || str_ends_with($lower, '_' . $sensitive)) {
                return true;
            }
        }
        return false;
    }

    private function isSearchableType(string $type): bool
    {
        return Str::contains(strtolower($type), ['char', 'text', 'string', 'uuid']);
    }

    private function encodeKey(array $row, array $columns): ?string
    {
        if (empty($columns)) return null;
        $key = [];
        foreach ($columns as $column) {
            if (! array_key_exists($column, $row)) return null;
            $key[$column] = $row[$column];
        }
        return rtrim(strtr(base64_encode(json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    private function decodeKey(string $key, array $columns): array
    {
        abort_if(empty($columns), 422, 'A tabela não possui uma chave segura para edição.');
        $padding = strlen($key) % 4;
        if ($padding) $key .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode(strtr($key, '-_', '+/'), true);
        $values = $decoded === false ? null : json_decode($decoded, true);
        abort_unless(is_array($values), 422, 'Chave de registro inválida.');

        $where = [];
        foreach ($columns as $column) {
            abort_unless(array_key_exists($column, $values), 422, 'Chave de registro incompleta.');
            $where[$column] = $values[$column];
        }
        return $where;
    }

    private function baseQuery(string $table, array $where)
    {
        $query = DB::table($table);
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }
        return $query;
    }

    private function findRow(string $table, array $where, array $meta): array
    {
        $visible = collect($meta['columns'])->where('visible', true)->pluck('name')->values()->all();
        $row = $this->baseQuery($table, $where)->select($visible)->first();
        abort_unless($row, 404, 'Registro não encontrado.');
        $result = (array) $row;
        $result['_admin_key'] = $this->encodeKey($result, $meta['key_columns']);
        return $result;
    }

    private function normalizeValue(mixed $value, array $column): mixed
    {
        if ($value === null) {
            abort_unless($column['nullable'], 422, "O campo {$column['name']} não aceita valor nulo.");
            return null;
        }

        $type = strtolower((string) $column['type']);
        if (Str::contains($type, ['json'])) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                abort_if(json_last_error() !== JSON_ERROR_NONE, 422, "JSON inválido no campo {$column['name']}.");
                return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (Str::contains($type, ['bool'])) return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        if ($type === 'tinyint' && in_array($value, [true, false, 0, 1, '0', '1'], true)) return (int) filter_var($value, FILTER_VALIDATE_BOOLEAN);
        if (Str::contains($type, ['int'])) return $value === '' && $column['nullable'] ? null : (int) $value;
        if (Str::contains($type, ['decimal', 'numeric', 'float', 'double', 'real'])) return $value === '' && $column['nullable'] ? null : $value;
        return $value === '' && $column['nullable'] ? null : $value;
    }

    private function audit(Request $request, string $table, array $where, array $before, array $after): void
    {
        $sanitize = function (array $row): array {
            return collect($row)->reject(fn ($value, $key) => $this->isSensitiveColumn((string) $key))->all();
        };
        $id = isset($where['id']) && is_numeric($where['id']) ? (int) $where['id'] : null;

        EcosystemAuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'admin.data.updated',
            'entity_type' => 'table:' . $table,
            'entity_id' => $id,
            'before' => $sanitize($before),
            'after' => $sanitize($after),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);
    }
}
