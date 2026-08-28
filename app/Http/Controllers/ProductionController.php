<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Interaction;
use App\Models\Production;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class ProductionController extends Controller
{
    public function list(Request $request)
    {
        $query = Production::query()->with('user')->latest();

        if ($request->filled('per_page')) {
            $perPage = max(1, min((int) $request->input('per_page'), 100));
            return response()->json(['productions' => $query->paginate($perPage)]);
        }

        return response()->json(['productions' => $query->get()]);
    }

    public function show($id)
    {
        $production = Production::query()->with('user')->findOrFail($id);

        return response()->json([
            'production' => $production,
            'productionEvents' => $production->events()->get(),
        ]);
    }

    public function view($slug)
    {
        $production = Production::query()->with('user')->where('slug', $slug)->firstOrFail();
        $userHasLikedPost = false;

        if (Auth::check()) {
            $userId = Auth::id();

            $userHasLikedPost = Interaction::query()->where([
                'user_id' => $userId,
                'entity_id' => $production->id,
                'entity_type' => 'production',
                'interaction_type' => 'like',
            ])->exists();

            Interaction::query()->firstOrCreate([
                'user_id' => $userId,
                'entity_id' => $production->id,
                'entity_type' => 'production',
                'interaction_type' => 'view',
            ]);
        }

        $nextEvent = Event::query()
            ->where('production_id', $production->id)
            ->where('start_date', '>', now())
            ->orderBy('start_date')
            ->first();

        $randomEvent = Event::query()
            ->where('production_id', '!=', $production->id)
            ->where('start_date', '>', now())
            ->inRandomOrder()
            ->first();

        $randomProduction = Production::query()
            ->whereKeyNot($production->id)
            ->inRandomOrder()
            ->first();

        $views = Interaction::query()
            ->where('entity_id', $production->id)
            ->where('entity_type', 'production')
            ->where('interaction_type', 'view')
            ->distinct('user_id')
            ->count('user_id');

        return response()->json([
            'views' => $views,
            'radonevent' => $randomEvent,
            'radonproduction' => $randomProduction,
            'nextevent' => $nextEvent,
            'productions' => Production::query()->latest()->get(),
            'production' => $production,
            'liked' => $userHasLikedPost,
            'productionEvents' => $production->events()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        if (! $user->hasProfile('Administrador') && ! $user->hasPermission('production_create')) {
            return response()->json(['error' => 'Você não tem permissão para criar uma produção.'], 403);
        }

        $data = $request->validate($this->rules(true));
        $data['user_id'] = $user->id;
        $data['slug'] = $this->uniqueSlug($data['name']);

        if (! $user->hasProfile('Administrador')) {
            unset($data['is_featured'], $data['is_approved']);
        }

        unset($data['logo'], $data['background']);

        $production = Production::create($data);
        $this->handleImages($request, $production);

        return response()->json([
            'message' => 'Produção cadastrada com sucesso.',
            'production' => $production->fresh(),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $production = Production::findOrFail($id);
        $user = Auth::user();

        $canUpdate = $user->hasProfile('Administrador')
            || (int) $production->user_id === (int) $user->id
            || $user->hasPermission('production_edit');

        if (! $canUpdate) {
            return response()->json(['error' => 'Você não tem permissão para atualizar esta produção.'], 403);
        }

        $data = $request->validate($this->rules(false));

        if (isset($data['name']) && $data['name'] !== $production->name) {
            $data['slug'] = $this->uniqueSlug($data['name'], $production->id);
        }

        if (! $user->hasProfile('Administrador')) {
            unset($data['is_featured'], $data['is_approved']);
        }

        unset($data['logo'], $data['background'], $data['user_id']);
        $production->update($data);
        $this->handleImages($request, $production);

        return response()->json([
            'message' => 'Produção atualizada com sucesso.',
            'production' => $production->fresh(),
        ]);
    }

    public function delete($id)
    {
        $production = Production::findOrFail($id);
        $user = Auth::user();

        $canDelete = $user->hasProfile('Administrador') || (
            (int) $production->user_id === (int) $user->id
            && $user->hasPermission('production_delete')
        );

        if (! $canDelete) {
            return response()->json(['error' => 'Você não tem permissão para excluir esta produção.'], 403);
        }

        $this->deletePublicImage($production->logo);
        $this->deletePublicImage($production->background);
        $production->delete();

        return response()->json(['message' => 'Produção excluída com sucesso.']);
    }

    public function getCompanyInfo(Request $request)
    {
        $data = $request->validate([
            'cnpj' => ['required', 'string', 'regex:/^\\d{14}$/'],
        ]);

        $cnpj = preg_replace('/\\D/', '', $data['cnpj']);

        try {
            $company = Cache::remember('receitaws:cnpj:' . $cnpj, now()->addHours(12), function () use ($cnpj) {
                $response = Http::acceptJson()
                    ->timeout(8)
                    ->retry(1, 200)
                    ->get('https://www.receitaws.com.br/v1/cnpj/' . $cnpj);

                if (! $response->successful()) {
                    throw new \RuntimeException('Consulta ReceitaWS retornou HTTP ' . $response->status());
                }

                return $response->json();
            });

            return response()->json($company);
        } catch (\Throwable $e) {
            Log::warning('Falha ao consultar CNPJ.', [
                'cnpj_suffix' => substr($cnpj, -4),
                'message' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Não foi possível consultar o CNPJ neste momento.'], 502);
        }
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required|' : 'sometimes|';

        return [
            'name' => $required . 'string|max:255',
            'cnpj' => 'sometimes|nullable|string|max:18',
            'fantasy' => 'sometimes|nullable|string|max:255',
            'type' => 'sometimes|nullable|string|max:100',
            'phone' => 'sometimes|nullable|string|max:30',
            'establishment_type' => 'sometimes|nullable|string|max:100',
            'description' => 'sometimes|nullable|string|max:10000',
            'segments' => 'sometimes|nullable|array',
            'segments.*' => 'string|max:100',
            'city' => 'sometimes|nullable|string|max:120',
            'location' => 'sometimes|nullable|string|max:255',
            'cep' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:255',
            'is_featured' => 'sometimes|boolean',
            'is_published' => 'sometimes|boolean',
            'is_approved' => 'sometimes|boolean',
            'is_cancelled' => 'sometimes|boolean',
            'additional_info' => 'sometimes|nullable|string|max:10000',
            'facebook_url' => 'sometimes|nullable|url|max:2048',
            'website_url' => 'sometimes|nullable|url|max:2048',
            'twitter_url' => 'sometimes|nullable|url|max:2048',
            'instagram_url' => 'sometimes|nullable|url|max:2048',
            'youtube_url' => 'sometimes|nullable|url|max:2048',
            'other_information' => 'sometimes|nullable|string|max:10000',
            'ticket_price_min' => 'sometimes|nullable|numeric|min:0',
            'ticket_price_max' => 'sometimes|nullable|numeric|min:0',
            'total_tickets_sold' => 'sometimes|nullable|integer|min:0',
            'total_tickets_available' => 'sometimes|nullable|integer|min:0',
            'logo' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'background' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
        ];
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: Str::lower(Str::random(8));
        $slug = $base;
        $suffix = 2;

        while (Production::query()
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }

    private function handleImages(Request $request, Production $production): void
    {
        $directory = public_path('images');
        File::ensureDirectoryExists($directory);

        foreach ([
            'logo' => [300, 300],
            'background' => [1920, 600],
        ] as $field => $dimensions) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $this->deletePublicImage($production->{$field});
            $file = $request->file($field);
            $extension = strtolower($file->extension() ?: 'jpg');
            $filename = $field . '_' . Str::uuid() . '.' . $extension;
            $absolutePath = $directory . DIRECTORY_SEPARATOR . $filename;

            Image::make($file->getRealPath())
                ->orientate()
                ->fit($dimensions[0], $dimensions[1])
                ->save($absolutePath, 85);

            $production->{$field} = 'images/' . $filename;
        }

        if ($production->isDirty(['logo', 'background'])) {
            $production->save();
        }
    }

    private function deletePublicImage(?string $path): void
    {
        if (! $path || ! Str::startsWith($path, 'images/')) {
            return;
        }

        $absolutePath = public_path($path);

        if (File::isFile($absolutePath)) {
            File::delete($absolutePath);
        }
    }
}
