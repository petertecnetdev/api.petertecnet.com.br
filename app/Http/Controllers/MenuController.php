<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class MenuController extends Controller
{
    public function list(Request $request)
    {
        $data = $request->validate([
            'establishment_id' => 'nullable|integer|exists:establishments,id',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json([
            'menus' => Menu::query()
                ->when(isset($data['establishment_id']), fn ($q) => $q->where('establishment_id', $data['establishment_id']))
                ->where('is_active', true)
                ->with('items')
                ->latest()
                ->paginate($data['per_page'] ?? 20),
        ]);
    }

    public function show($id)
    {
        $menu = Menu::with(['items', 'establishment:id,name,fantasy,slug,user_id'])->findOrFail($id);
        Interaction::registerView($menu, Auth::user());
        return response()->json(['menu' => $menu]);
    }

    public function store(Request $request)
    {
        $data = $this->validateMenu($request, true);
        $establishment = Establishment::findOrFail($data['establishment_id']);
        $this->assertOwner($establishment);
        $data['slug'] = $this->uniqueSlug($data['name'], $establishment->id);

        $menu = DB::transaction(function () use ($request, $data) {
            $menu = Menu::create($data);
            if ($request->hasFile('cover_image')) {
                $menu->cover_image = $this->storeImage($request->file('cover_image'));
                $menu->save();
            }
            return $menu;
        });

        Interaction::register('create', $menu, Auth::user());
        return response()->json(['message' => 'Menu criado com sucesso.', 'menu' => $menu], 201);
    }

    public function update(Request $request, $id)
    {
        $menu = Menu::with(['establishment', 'items'])->findOrFail($id);
        $this->assertOwner($menu->establishment);
        $data = $this->validateMenu($request, false);

        if (isset($data['establishment_id']) && (int) $data['establishment_id'] !== (int) $menu->establishment_id) {
            $target = Establishment::findOrFail($data['establishment_id']);
            $this->assertOwner($target);
        }

        DB::transaction(function () use ($request, $menu, $data) {
            if (isset($data['name'])) {
                $data['slug'] = $this->uniqueSlug($data['name'], (int) ($data['establishment_id'] ?? $menu->establishment_id), $menu->id);
            }
            $menu->fill(collect($data)->except(['cover_image', 'items', 'all_items'])->all());

            if ($request->hasFile('cover_image')) {
                $old = $menu->cover_image;
                $menu->cover_image = $this->storeImage($request->file('cover_image'));
                $this->deleteImage($old);
            }
            $menu->save();

            if ($request->boolean('all_items')) {
                $items = $menu->establishment->items()->where('status', true)->pluck('id');
                $sync = $items->mapWithKeys(fn ($id) => [$id => ['display_order' => 0, 'is_active' => true, 'price_override' => null]])->all();
                $menu->items()->sync($sync);
            } elseif (isset($data['items'])) {
                $validItemIds = $menu->establishment->items()->pluck('id')->map(fn ($id) => (int) $id)->all();
                $sync = [];
                foreach ($data['items'] as $row) {
                    abort_unless(in_array((int) $row['item_id'], $validItemIds, true), 422, 'Um item informado não pertence a este estabelecimento.');
                    $sync[$row['item_id']] = [
                        'display_order' => $row['display_order'] ?? 0,
                        'is_active' => $row['is_active'] ?? true,
                        'price_override' => $row['price_override'] ?? null,
                    ];
                }
                $menu->items()->sync($sync);
            }
        });

        Interaction::registerUpdate($menu, Auth::user(), []);
        return response()->json(['message' => 'Menu atualizado com sucesso.', 'menu' => $menu->fresh()->load('items')]);
    }

    public function destroy($id)
    {
        $menu = Menu::with('establishment')->findOrFail($id);
        $this->assertOwner($menu->establishment);
        $image = $menu->cover_image;
        $menu->delete();
        $this->deleteImage($image);
        return response()->json(['message' => 'Menu excluído com sucesso.']);
    }

    private function validateMenu(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        return $request->validate([
            'establishment_id' => "$required|integer|exists:establishments,id",
            'name' => "$required|string|max:255",
            'description' => 'sometimes|nullable|string|max:10000',
            'valid_from' => 'sometimes|nullable|date',
            'valid_to' => 'sometimes|nullable|date|after_or_equal:valid_from',
            'price_modifier_percent' => 'sometimes|nullable|numeric|min:-100|max:1000',
            'is_active' => 'sometimes|boolean',
            'cover_image' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'all_items' => 'sometimes|boolean',
            'items' => 'sometimes|array|max:500',
            'items.*.item_id' => 'required|integer|exists:items,id',
            'items.*.display_order' => 'nullable|integer|min:0',
            'items.*.is_active' => 'nullable|boolean',
            'items.*.price_override' => 'nullable|numeric|min:0',
        ]);
    }

    private function assertOwner(Establishment $establishment): void
    {
        $user = Auth::user();
        abort_unless($user && (
            $user->hasProfile('Administrador')
            || (int) $establishment->user_id === (int) $user->id
            || (! $establishment->user_id && (int) $establishment->created_by === (int) $user->id)
        ), 403, 'Você não pode gerenciar o menu deste estabelecimento.');
    }

    private function uniqueSlug(string $name, int $establishmentId, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: Str::random(12);
        $slug = $base;
        $i = 2;
        while (Menu::query()->where('establishment_id', $establishmentId)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    private function storeImage($uploaded): string
    {
        $path = 'images/menus/' . Str::uuid() . '.webp';
        $absolute = Storage::disk('public')->path($path);
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        Image::make($uploaded->getRealPath())->orientate()->fit(800, 600)->encode('webp', 85)->save($absolute);
        return $path;
    }

    private function deleteImage(?string $path): void
    {
        if ($path && str_starts_with($path, 'images/menus/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
