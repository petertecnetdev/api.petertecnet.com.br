<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\File;
use App\Models\Interaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EstablishmentController extends Controller
{
    public function store(Request $request)
    {
        $data = $this->validateEstablishment($request, true);
        $user = Auth::user();
        $data['city'] = $data['city'] ?? $user->city;
        $data['uf'] = isset($data['uf']) ? strtoupper($data['uf']) : $user->uf;
        $data['slug'] = $this->uniqueSlug($data['fantasy'] ?? $data['name']);
        $data['user_id'] = $user->id;
        $data['created_by'] = $user->id;
        $data['updated_by'] = $user->id;

        $establishment = DB::transaction(function () use ($request, $data, $user) {
            $establishment = Establishment::create($data);
            $this->storeMedia($request, $establishment, $user->id);

            $user->applications()->syncWithoutDetaching([
                $establishment->app_id => ['status' => 'active', 'joined_at' => now()],
            ]);

            return $establishment;
        });

        return response()->json([
            'message' => 'Estabelecimento criado com sucesso!',
            'establishment' => $establishment->fresh()->load('files'),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $establishment = Establishment::findOrFail($id);
        $this->assertOwner($establishment);
        $data = $this->validateEstablishment($request, false);

        if (isset($data['app_id']) && (int) $data['app_id'] !== (int) $establishment->app_id) {
            return response()->json([
                'error' => 'A aplicação do estabelecimento é imutável. Crie outro estabelecimento na aplicação desejada.',
            ], 409);
        }

        if (isset($data['name']) || isset($data['fantasy'])) {
            $data['slug'] = $this->uniqueSlug(
                $data['fantasy'] ?? $data['name'] ?? $establishment->fantasy ?? $establishment->name,
                $establishment->id
            );
        }
        if (isset($data['uf'])) {
            $data['uf'] = strtoupper($data['uf']);
        }

        DB::transaction(function () use ($request, $data, $establishment) {
            $establishment->fill($data);
            $establishment->updated_by = Auth::id();
            $establishment->save();
            $this->storeMedia($request, $establishment, Auth::id(), true);
        });

        return response()->json([
            'message' => 'Estabelecimento atualizado com sucesso.',
            'establishment' => $establishment->fresh()->load('files'),
        ]);
    }

    public function destroy($id)
    {
        $establishment = Establishment::with(['files', 'items.files'])->findOrFail($id);
        $this->assertOwner($establishment);

        DB::transaction(function () use ($establishment) {
            foreach ($establishment->items as $item) {
                foreach ($item->files as $file) {
                    $this->deleteFile($file);
                }
                $item->delete();
            }
            foreach ($establishment->files as $file) {
                $this->deleteFile($file);
            }
            $establishment->delete();
        });

        return response()->json(['message' => 'Estabelecimento excluído com sucesso.']);
    }

    public function show(Request $request, $id)
    {
        $data = $request->validate(['app_id' => 'nullable|integer|exists:applications,id']);

        $establishment = Establishment::query()
            ->when(isset($data['app_id']), fn ($q) => $q->where('app_id', $data['app_id']))
            ->with(['user:id,first_name,last_name,user_name,avatar', 'files' => $this->publicFiles()])
            ->findOrFail($id);

        Interaction::registerView($establishment, Auth::user());

        return response()->json([
            'message' => 'Estabelecimento encontrado com sucesso.',
            'establishment' => $establishment,
            'owner' => $establishment->user,
        ]);
    }

    public function list(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'nullable|integer|exists:applications,id',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json([
            'message' => 'Estabelecimentos listados com sucesso.',
            'establishments' => Establishment::query()
                ->where('is_cancelled', false)
                ->when(isset($data['app_id']), fn ($q) => $q->where('app_id', $data['app_id']))
                ->with(['files' => $this->publicFiles()])
                ->latest()
                ->paginate($data['per_page'] ?? 10),
        ]);
    }

    public function listByCategory(Request $request, $category)
    {
        $data = $request->validate([
            'app_id' => 'nullable|integer|exists:applications,id',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json([
            'message' => 'Estabelecimentos listados por categoria com sucesso.',
            'establishments' => Establishment::query()
                ->where('is_cancelled', false)
                ->when(isset($data['app_id']), fn ($q) => $q->where('app_id', $data['app_id']))
                ->when($category, fn ($q) => $q->where('category', $category))
                ->with(['files' => $this->publicFiles()])
                ->paginate($data['per_page'] ?? 10),
        ]);
    }

    public function listCities($app_id)
    {
        abort_unless(is_numeric($app_id), 422, 'app_id inválido.');
        return response()->json([
            'message' => 'Cidades listadas com sucesso.',
            'cities' => Establishment::query()
                ->where('app_id', (int) $app_id)
                ->where('is_cancelled', false)
                ->whereNotNull('city')
                ->whereNotNull('uf')
                ->select('city', 'uf')
                ->distinct()
                ->orderBy('city')
                ->get(),
        ]);
    }

    public function view(Request $request, string $identifier)
    {
        $data = $request->validate(['app_id' => 'nullable|integer|exists:applications,id']);
        $establishment = $this->findByIdentifier($identifier, $data['app_id'] ?? null)
            ->load(['files' => $this->publicFiles()]);
        Interaction::registerView($establishment, Auth::user());

        return response()->json([
            'success' => true,
            'message' => 'Estabelecimento encontrado com sucesso.',
            'establishment' => $establishment,
        ]);
    }

    public function home(Request $request, $app_id)
    {
        $data = $request->validate([
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|max:2',
        ]);

        $query = Establishment::query()
            ->where('app_id', (int) $app_id)
            ->where('is_cancelled', false)
            ->when(! empty($data['city']) && $data['city'] !== 'Todas', fn ($q) => $q->where('city', $data['city']))
            ->when(! empty($data['uf']) && $data['uf'] !== 'ALL', fn ($q) => $q->where('uf', strtoupper($data['uf'])))
            ->with(['files' => $this->publicFiles()]);

        return response()->json([
            'success' => true,
            'city' => $data['city'] ?? null,
            'uf' => $data['uf'] ?? null,
            'establishments' => $query->get(),
        ]);
    }

    public function listOthers(string $identifier)
    {
        $current = $this->findByIdentifier($identifier);
        $establishments = Establishment::query()
            ->where('app_id', $current->app_id)
            ->where('id', '!=', $current->id)
            ->where('is_cancelled', false)
            ->when($current->city, fn ($q) => $q->where('city', $current->city))
            ->when($current->uf, fn ($q) => $q->where('uf', $current->uf))
            ->with(['files' => $this->publicFiles()])
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['success' => true, 'message' => 'Estabelecimentos listados com sucesso.', 'establishments' => $establishments]);
    }

    public function myEstablishments(Request $request)
    {
        $data = $request->validate(['app_id' => 'nullable|integer|exists:applications,id']);

        return response()->json([
            'message' => 'Estabelecimentos do usuário listados com sucesso.',
            'establishments' => Establishment::query()
                ->where('user_id', Auth::id())
                ->when(isset($data['app_id']), fn ($q) => $q->where('app_id', $data['app_id']))
                ->with('files')
                ->latest()
                ->get(),
        ]);
    }

    public function listByUser(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'nullable|integer|exists:applications,id',
            'category' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json([
            'message' => 'Estabelecimentos listados com sucesso.',
            'establishments' => Establishment::query()
                ->where('user_id', Auth::id())
                ->when(isset($data['app_id']), fn ($q) => $q->where('app_id', $data['app_id']))
                ->when(! empty($data['category']), fn ($q) => $q->where('category', $data['category']))
                ->latest()
                ->paginate($data['per_page'] ?? 10),
        ]);
    }

    public function listMyByCategory(Request $request, $category)
    {
        $data = $request->validate([
            'app_id' => 'nullable|integer|exists:applications,id',
            'q' => 'nullable|string|max:200',
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort' => 'nullable|in:name,city,created_at',
            'dir' => 'nullable|in:asc,desc',
        ]);
        $categories = array_values(array_filter(array_map('trim', explode(',', (string) $category))));
        abort_if($categories === [], 422, 'Categoria inválida.');

        $query = Establishment::query()
            ->where('user_id', Auth::id())
            ->when(isset($data['app_id']), fn ($q) => $q->where('app_id', $data['app_id']))
            ->whereIn('category', $categories)
            ->when(! empty($data['q']), function ($q) use ($data) {
                $like = '%' . $data['q'] . '%';
                $q->where(fn ($qq) => $qq->where('name', 'like', $like)->orWhere('fantasy', 'like', $like)->orWhere('city', 'like', $like));
            });

        return response()->json([
            'message' => 'Estabelecimentos do usuário listados por categoria com sucesso.',
            'establishments' => $query
                ->orderBy($data['sort'] ?? 'name', $data['dir'] ?? 'asc')
                ->paginate($data['per_page'] ?? 10),
        ]);
    }

    public function listMyByApp(Request $request)
    {
        $data = $request->validate(['app_id' => 'required|integer|exists:applications,id']);
        return response()->json([
            'message' => 'Estabelecimentos do usuário listados com sucesso.',
            'establishments' => Establishment::query()
                ->where('app_id', $data['app_id'])
                ->where('user_id', Auth::id())
                ->with('files')
                ->latest()
                ->get(),
        ]);
    }

    public function generatePdf(Request $request, $slug)
    {
        $data = $request->validate(['app_id' => 'nullable|integer|exists:applications,id']);
        $establishment = Establishment::query()
            ->when(isset($data['app_id']), fn ($q) => $q->where('app_id', $data['app_id']))
            ->where('slug', $slug)
            ->firstOrFail();

        $items = $establishment->items()
            ->where('app_id', $establishment->app_id)
            ->where('status', true)
            ->get();
        $establishment->setRelation('items', $items);

        $grouped = $items->groupBy(fn ($item) => $item->category ?: 'Outros');
        $category = strtolower((string) $establishment->category);
        $tipo = in_array($category, ['barbershop', 'beauty', 'salon'], true) ? 'Tabela de Preços' : 'Catálogo';

        $pdf = \PDF::loadView('pdf.establishment-menu', [
            'establishment' => $establishment,
            'grouped' => $grouped,
            'tipo' => $tipo,
            'logoPath' => public_path('images/default-logo.png'),
            'itemsBase64' => [],
        ])->setPaper('a4');

        return $pdf->download(Str::slug($establishment->name . '-' . $tipo) . '.pdf');
    }

    private function validateEstablishment(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        return $request->validate([
            'app_id' => "$required|integer|exists:applications,id",
            'name' => "$required|string|max:255",
            'fantasy' => 'sometimes|nullable|string|max:255',
            'cnpj' => 'sometimes|nullable|string|max:20',
            'type' => 'sometimes|nullable|string|max:100',
            'category' => 'sometimes|nullable|string|max:100',
            'phone' => 'sometimes|nullable|string|max:30',
            'email' => 'sometimes|nullable|email|max:255',
            'description' => 'sometimes|nullable|string|max:10000',
            'additional_info' => 'sometimes|nullable|string|max:10000',
            'city' => 'sometimes|nullable|string|max:120',
            'uf' => 'sometimes|nullable|string|size:2',
            'location' => 'sometimes|nullable|string|max:500',
            'cep' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|url|max:500',
            'facebook_url' => 'sometimes|nullable|url|max:500',
            'instagram_url' => 'sometimes|nullable|url|max:500',
            'twitter_url' => 'sometimes|nullable|url|max:500',
            'youtube_url' => 'sometimes|nullable|url|max:500',
            'segments' => 'sometimes|nullable|array',
            'segments.*' => 'string|max:100',
            'is_featured' => 'sometimes|boolean',
            'is_published' => 'sometimes|boolean',
            'is_approved' => 'sometimes|boolean',
            'is_cancelled' => 'sometimes|boolean',
            'logo' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'background' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:8192',
        ]);
    }

    private function assertOwner(Establishment $establishment): void
    {
        $user = Auth::user();
        abort_unless($user && (
            $user->hasProfile('Administrador')
            || (int) $establishment->user_id === (int) $user->id
            || (int) $establishment->created_by === (int) $user->id
        ), 403, 'Acesso negado.');
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: Str::random(12);
        $slug = $base;
        $i = 2;

        while (Establishment::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    private function findByIdentifier(string $identifier, ?int $appId = null): Establishment
    {
        return Establishment::query()
            ->where('is_cancelled', false)
            ->when($appId !== null, fn ($q) => $q->where('app_id', $appId))
            ->when(is_numeric($identifier), fn ($q) => $q->where('id', (int) $identifier), fn ($q) => $q->where('slug', $identifier))
            ->firstOrFail();
    }

    private function storeMedia(Request $request, Establishment $establishment, int $userId, bool $replace = false): void
    {
        foreach (['logo', 'background'] as $type) {
            if (! $request->hasFile($type)) {
                continue;
            }
            if ($replace) {
                foreach ($establishment->files()->where('type', $type)->get() as $old) {
                    $this->deleteFile($old);
                }
            }
            File::storeOne($request->file($type), 'establishment', $establishment->id, $type, $establishment->app_id, $userId);
        }
    }

    private function deleteFile(File $file): void
    {
        if ($file->path) {
            Storage::disk('public')->delete($file->path);
        }
        $file->delete();
    }

    private function publicFiles(): \Closure
    {
        return fn ($q) => $q->where('visibility', 'public')->where('status', 'active')->orderBy('position');
    }
}
