<?php

namespace App\Http\Controllers;

use App\Models\Production;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class CutinappController extends Controller
{
    public function config()
    {
        return response()->json([
            'google_client_id' => (string) config('services.google.client_id'),
            'app' => 'cutinapp',
        ]);
    }

    public function myProductions()
    {
        return response()->json([
            'productions' => Production::query()
                ->where('user_id', Auth::id())
                ->withCount('events')
                ->latest()
                ->get(),
        ]);
    }

    public function createProduction(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'fantasy' => 'nullable|string|max:255',
            'cnpj' => 'nullable|string|max:18',
            'phone' => 'nullable|string|max:30',
            'description' => 'nullable|string|max:10000',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|max:2',
            'address' => 'nullable|string|max:255',
            'website_url' => 'nullable|url|max:2048',
            'instagram_url' => 'nullable|url|max:2048',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'background' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        $data['user_id'] = Auth::id();
        $data['slug'] = $this->uniqueProductionSlug($data['name']);
        $data['is_published'] = true;
        $data['is_cancelled'] = false;
        unset($data['logo'], $data['background']);

        $production = Production::create($data);

        foreach (['logo' => [600, 600], 'background' => [1920, 700]] as $field => $size) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $path = 'images/cutinapp/productions/' . $field . '-' . Str::uuid() . '.webp';
            $absolute = Storage::disk('public')->path($path);
            if (! is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0755, true);
            }

            Image::make($request->file($field)->getRealPath())
                ->orientate()
                ->fit($size[0], $size[1])
                ->encode('webp', 86)
                ->save($absolute);

            $production->{$field} = $path;
        }

        $production->save();

        return response()->json([
            'message' => 'Produção criada. Agora você já pode cadastrar seu primeiro evento.',
            'production' => $production->fresh(),
        ], 201);
    }

    private function uniqueProductionSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'producao-' . Str::lower(Str::random(8));
        $slug = $base;
        $counter = 2;

        while (Production::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }
}
