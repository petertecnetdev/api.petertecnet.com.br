<?php

namespace App\Domain\DeveloperPlatform\Services;

use App\Domain\DeveloperPlatform\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class SandboxDataService
{
    public function establishments(Request $request): JsonResponse
    {
        $items = collect($this->establishmentFixtures());
        $items = $this->filter($items, $request, ['name', 'legal_name', 'description', 'category', 'city'], ['city', 'state', 'type', 'category']);
        $items = $this->sort($items, $request, ['name', 'city', 'created_at']);

        return $this->paginate($items, $request);
    }

    public function establishment(Request $request, string $slug): JsonResponse
    {
        $item = collect($this->establishmentFixtures())->firstWhere('slug', $slug);

        return $item
            ? ApiResponse::data($item)
            : ApiResponse::error($request, 'resource_not_found', 'Estabelecimento sandbox não encontrado.', 404);
    }

    public function items(Request $request): JsonResponse
    {
        $items = collect($this->itemFixtures());
        $items = $this->filter($items, $request, ['name', 'description', 'category', 'subcategory', 'brand'], ['type', 'category', 'brand']);

        if ($request->filled('establishment_id')) {
            $wanted = (int) $request->input('establishment_id');
            $items = $items->filter(fn (array $item) => (int) ($item['owner']['id'] ?? 0) === $wanted);
        }

        $items = $this->sort($items, $request, ['name', 'price', 'created_at']);
        return $this->paginate($items, $request);
    }

    public function item(Request $request, string $slug): JsonResponse
    {
        $item = collect($this->itemFixtures())->firstWhere('slug', $slug);

        return $item
            ? ApiResponse::data($item)
            : ApiResponse::error($request, 'resource_not_found', 'Item sandbox não encontrado.', 404);
    }

    private function filter(Collection $items, Request $request, array $searchFields, array $filters): Collection
    {
        if ($request->filled('q')) {
            $term = mb_strtolower(trim($request->string('q')->toString()));
            $items = $items->filter(function (array $item) use ($searchFields, $term) {
                foreach ($searchFields as $field) {
                    if (str_contains(mb_strtolower((string) ($item[$field] ?? '')), $term)) {
                        return true;
                    }
                }
                return false;
            });
        }

        foreach ($filters as $filter) {
            $requestKey = $filter === 'state' ? 'uf' : $filter;
            if ($request->filled($requestKey)) {
                $value = mb_strtolower($request->string($requestKey)->toString());
                $items = $items->filter(fn (array $item) => mb_strtolower((string) ($item[$filter] ?? '')) === $value);
            }
        }

        return $items->values();
    }

    private function sort(Collection $items, Request $request, array $allowed): Collection
    {
        $sort = $request->string('sort', '-created_at')->toString();
        $descending = str_starts_with($sort, '-');
        $field = ltrim($sort, '-');

        if (!in_array($field, $allowed, true)) {
            $field = 'created_at';
            $descending = true;
        }

        return ($descending ? $items->sortByDesc($field) : $items->sortBy($field))->values();
    }

    private function paginate(Collection $items, Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 20), 1), (int) config('developer.max_per_page', 100));
        $page = max((int) $request->input('page', 1), 1);
        $paginator = new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return ApiResponse::paginated($paginator, fn (array $item) => $item);
    }

    private function establishmentFixtures(): array
    {
        return [
            [
                'id' => 900001,
                'slug' => 'loja-exemplo-sandbox',
                'name' => 'Loja Exemplo Sandbox',
                'legal_name' => 'Empresa Exemplo Sandbox Ltda.',
                'type' => 'commerce',
                'category' => 'materiais',
                'description' => 'Registro sintético para desenvolvimento. Não representa uma empresa real.',
                'city' => 'São Paulo',
                'state' => 'SP',
                'segments' => ['sandbox', 'commerce'],
                'featured' => true,
                'links' => [],
                'created_at' => '2026-01-10T12:00:00Z',
                'updated_at' => '2026-08-20T15:30:00Z',
            ],
            [
                'id' => 900002,
                'slug' => 'servicos-exemplo-sandbox',
                'name' => 'Serviços Exemplo Sandbox',
                'legal_name' => 'Serviços Exemplo Sandbox Ltda.',
                'type' => 'services',
                'category' => 'agendamentos',
                'description' => 'Segundo registro sintético para testes de paginação e filtros.',
                'city' => 'Belo Horizonte',
                'state' => 'MG',
                'segments' => ['sandbox', 'services'],
                'featured' => false,
                'links' => [],
                'created_at' => '2026-02-12T12:00:00Z',
                'updated_at' => '2026-08-22T10:00:00Z',
            ],
        ];
    }

    private function itemFixtures(): array
    {
        return [
            [
                'id' => 910001,
                'slug' => 'produto-exemplo-sandbox',
                'name' => 'Produto Exemplo Sandbox',
                'type' => 'product',
                'description' => 'Produto sintético para testar uma integração de catálogo.',
                'price' => '29.90',
                'category' => 'materiais',
                'subcategory' => 'exemplos',
                'brand' => 'Sandbox',
                'tags' => ['sandbox', 'example'],
                'featured' => true,
                'image_url' => null,
                'owner' => ['type' => 'establishment', 'id' => 900001],
                'availability' => ['starts_at' => null, 'ends_at' => null],
                'created_at' => '2026-03-01T12:00:00Z',
                'updated_at' => '2026-08-25T12:00:00Z',
            ],
            [
                'id' => 910002,
                'slug' => 'servico-exemplo-sandbox',
                'name' => 'Serviço Exemplo Sandbox',
                'type' => 'service',
                'description' => 'Serviço sintético para validar consumidores multi-tipo.',
                'price' => '80.00',
                'category' => 'agendamentos',
                'subcategory' => 'exemplos',
                'brand' => 'Sandbox',
                'tags' => ['sandbox', 'service'],
                'featured' => false,
                'image_url' => null,
                'owner' => ['type' => 'establishment', 'id' => 900002],
                'availability' => ['starts_at' => null, 'ends_at' => null],
                'created_at' => '2026-04-01T12:00:00Z',
                'updated_at' => '2026-08-26T12:00:00Z',
            ],
        ];
    }
}
