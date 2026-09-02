<?php

namespace App\Domain\Organizations\Services;

use App\Models\Establishment;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class EstablishmentService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function paginatePublic(Request $request): LengthAwarePaginator
    {
        $query = Establishment::query()
            ->where('app_id', $this->context->id())
            ->where('is_cancelled', false)
            ->where('is_published', true);
        if (in_array($this->context->slug(), config('platform.approval_required_apps', []), true)) $query->where('is_approved', true);
        if ($request->filled('city')) $query->where('city', $request->string('city'));
        if ($request->filled('uf')) $query->where('uf', $request->string('uf'));
        if ($request->filled('q')) {
            $term = '%' . trim((string) $request->query('q')) . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('fantasy', 'like', $term)->orWhere('description', 'like', $term));
        }
        $pageSize = min(max((int) $request->query('per_page', config('platform.default_page_size', 20)), 1), config('platform.max_page_size', 100));
        return $query->latest('id')->paginate($pageSize);
    }

    public function publicBySlug(string $slug): Establishment
    {
        $query = Establishment::query()->where('app_id', $this->context->id())->where('slug', $slug)
            ->where('is_cancelled', false)->where('is_published', true);
        if (in_array($this->context->slug(), config('platform.approval_required_apps', []), true)) $query->where('is_approved', true);
        return $query->firstOrFail();
    }

    public function mine(int $userId)
    {
        return Establishment::query()->where('app_id', $this->context->id())->where('user_id', $userId)
            ->where('is_cancelled', false)->latest('id')->get();
    }

    public function create(array $data, User $user): Establishment
    {
        $data['app_id'] = $this->context->id();
        $data['user_id'] = $user->id;
        $data['created_by'] = $user->id;
        $data['updated_by'] = $user->id;
        $data['slug'] = $this->uniqueSlug($data['fantasy'] ?? $data['name']);
        $data['is_featured'] = false;
        $data['is_approved'] = false;
        $data['is_cancelled'] = false;
        $data['is_published'] = false;
        $establishment = Establishment::create($data);
        $user->applications()->syncWithoutDetaching([$this->context->id() => ['status' => 'active', 'joined_at' => now()]]);
        return $establishment;
    }

    public function update(int $id, array $data, User $user): Establishment
    {
        $model = $this->owned($id, $user->id);
        $data['updated_by'] = $user->id;
        $model->fill($data)->save();
        return $model->fresh();
    }

    public function cancel(int $id, User $user): void
    {
        $model = $this->owned($id, $user->id);
        $model->update(['is_cancelled' => true, 'is_published' => false, 'updated_by' => $user->id]);
    }

    private function owned(int $id, int $userId): Establishment
    {
        return Establishment::query()->whereKey($id)->where('app_id', $this->context->id())
            ->where('user_id', $userId)->where('is_cancelled', false)->firstOrFail();
    }

    private function uniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'establishment';
        $slug = $base;
        $counter = 2;
        while (Establishment::where('slug', $slug)->exists()) $slug = $base . '-' . $counter++;
        return $slug;
    }
}
