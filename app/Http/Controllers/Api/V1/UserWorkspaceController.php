<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SavedView;
use App\Models\UserPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserWorkspaceController extends Controller
{
    public function preferences(Request $request, string $namespace): JsonResponse
    {
        $this->validateNamespace($namespace);

        $preferences = UserPreference::query()
            ->where('user_id', $request->user()->id)
            ->where('namespace', $namespace)
            ->orderBy('key')
            ->get()
            ->mapWithKeys(fn (UserPreference $preference) => [$preference->key => $preference->value]);

        return response()->json(['namespace' => $namespace, 'preferences' => $preferences]);
    }

    public function putPreference(Request $request, string $namespace, string $key): JsonResponse
    {
        $this->validateNamespace($namespace);
        $this->validateKey($key);
        $data = $request->validate(['value' => ['present']]);

        $preference = UserPreference::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'namespace' => $namespace, 'key' => $key],
            ['value' => $data['value']]
        );

        return response()->json(['preference' => $preference]);
    }

    public function deletePreference(Request $request, string $namespace, string $key): JsonResponse
    {
        $this->validateNamespace($namespace);
        $this->validateKey($key);

        UserPreference::query()
            ->where('user_id', $request->user()->id)
            ->where('namespace', $namespace)
            ->where('key', $key)
            ->delete();

        return response()->json(['success' => true]);
    }

    public function views(Request $request, string $scope): JsonResponse
    {
        $this->validateScope($scope);

        return response()->json([
            'scope' => $scope,
            'views' => SavedView::query()
                ->where('user_id', $request->user()->id)
                ->where('scope', $scope)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeView(Request $request, string $scope): JsonResponse
    {
        $this->validateScope($scope);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'configuration' => ['required', 'array'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['is_default'])) {
            SavedView::query()->where('user_id', $request->user()->id)->where('scope', $scope)->update(['is_default' => false]);
        }

        $view = SavedView::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'scope' => $scope, 'name' => trim($data['name'])],
            ['configuration' => $data['configuration'], 'is_default' => (bool) ($data['is_default'] ?? false)]
        );

        return response()->json(['view' => $view], $view->wasRecentlyCreated ? 201 : 200);
    }

    public function updateView(Request $request, SavedView $view): JsonResponse
    {
        abort_unless((int) $view->user_id === (int) $request->user()->id, 404);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'configuration' => ['sometimes', 'required', 'array'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (($data['is_default'] ?? false) === true) {
            SavedView::query()->where('user_id', $request->user()->id)->where('scope', $view->scope)->whereKeyNot($view->id)->update(['is_default' => false]);
        }

        if (isset($data['name'])) {
            $duplicate = SavedView::query()->where('user_id', $request->user()->id)->where('scope', $view->scope)->where('name', trim($data['name']))->whereKeyNot($view->id)->exists();
            abort_if($duplicate, 422, 'Já existe uma visualização com este nome neste contexto.');
            $data['name'] = trim($data['name']);
        }

        $view->update($data);
        return response()->json(['view' => $view->fresh()]);
    }

    public function deleteView(Request $request, SavedView $view): JsonResponse
    {
        abort_unless((int) $view->user_id === (int) $request->user()->id, 404);
        $view->delete();
        return response()->json(['success' => true]);
    }

    private function validateNamespace(string $namespace): void
    {
        validator(['namespace' => $namespace], ['namespace' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/i']])->validate();
    }

    private function validateScope(string $scope): void
    {
        validator(['scope' => $scope], ['scope' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/i']])->validate();
    }

    private function validateKey(string $key): void
    {
        validator(['key' => $key], ['key' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9._-]+$/i']])->validate();
    }
}
