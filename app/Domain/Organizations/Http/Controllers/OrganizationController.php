<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Domain\Organizations\Services\OrganizationService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class OrganizationController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly OrganizationService $organizations,
    ) {}

    public function publicIndex(Request $request)
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'lat' => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng' => 'nullable|numeric|between:-180,180|required_with:lat',
            'radius_km' => 'nullable|integer|min:1|max:500',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        return response()->json([
            'organizations' => $this->organizations->publicIndex(
                $this->context->id(),
                $data,
                $request->query(),
            ),
        ]);
    }

    public function publicShow(Request $request, string $slug)
    {
        return response()->json($this->organizations->publicShow(
            $this->context->id(),
            $slug,
            $request->bearerToken(),
        ));
    }

    public function mine(Request $request)
    {
        return response()->json([
            'organizations' => $this->organizations->mine(
                $this->context->id(),
                $request->user(),
            ),
        ]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json([
            'organization' => $this->organizations->showOwned(
                $this->context->id(),
                $request->user(),
                $id,
            ),
        ]);
    }

    public function store(Request $request)
    {
        $this->normalize($request);
        $data = $request->validate($this->rules(true));

        $organization = $this->organizations->create(
            $this->context->id(),
            $this->context->slug(),
            $request->user(),
            $data,
            $request->file('logo'),
            $request->file('background'),
        );

        return response()->json([
            'message' => 'Organização criada com sucesso.',
            'organization' => $organization,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $this->normalize($request);
        $data = $request->validate($this->rules(false));

        $organization = $this->organizations->update(
            $this->context->id(),
            $this->context->slug(),
            $request->user(),
            $id,
            $data,
            $request->file('logo'),
            $request->file('background'),
        );

        return response()->json([
            'message' => 'Organização atualizada com sucesso.',
            'organization' => $organization,
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->organizations->delete(
            $this->context->id(),
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Organização excluída com sucesso.']);
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required|' : 'sometimes|';

        return [
            'name' => $required.'string|min:2|max:255',
            'fantasy' => 'sometimes|nullable|string|max:255',
            'cnpj' => ['sometimes', 'nullable', 'regex:/^\d{14}$/'],
            'phone' => 'sometimes|nullable|string|max:30',
            'description' => 'sometimes|nullable|string|max:10000',
            'city' => 'sometimes|nullable|string|max:120',
            'uf' => 'sometimes|nullable|string|size:2',
            'address' => 'sometimes|nullable|string|max:255',
            'website_url' => 'sometimes|nullable|url:http,https|max:2048',
            'instagram_url' => 'sometimes|nullable|url:http,https|max:2048',
            'logo' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'background' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
        ];
    }

    private function normalize(Request $request): void
    {
        $merge = [];

        foreach (['name', 'fantasy', 'phone', 'description', 'city', 'address'] as $field) {
            if ($request->exists($field)) {
                $merge[$field] = trim((string) $request->input($field));
            }
        }

        if ($request->exists('uf')) {
            $merge['uf'] = strtoupper(trim((string) $request->input('uf')));
        }

        if ($request->filled('cnpj')) {
            $merge['cnpj'] = preg_replace('/\D+/', '', (string) $request->input('cnpj'));
        }

        if ($request->exists('website_url')) {
            $value = trim((string) $request->input('website_url'));
            $merge['website_url'] = $value === ''
                ? null
                : (preg_match('#^https?://#i', $value) ? $value : 'https://'.ltrim($value, '/'));
        }

        if ($request->exists('instagram_url')) {
            $value = trim((string) $request->input('instagram_url'));

            if ($value === '') {
                $merge['instagram_url'] = null;
            } elseif (preg_match('#^https?://#i', $value)) {
                $merge['instagram_url'] = $value;
            } else {
                $handle = ltrim($value, '@/');
                $merge['instagram_url'] = str_contains($handle, 'instagram.com/')
                    ? 'https://'.$handle
                    : 'https://instagram.com/'.$handle;
            }
        }

        if ($merge) {
            $request->merge($merge);
        }
    }
}
