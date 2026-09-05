<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcosystemAuditLog;
use App\Models\Establishment;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminEstablishmentMediaController extends Controller
{
    public function store(Request $request, Establishment $establishment): JsonResponse
    {
        $data = $request->validate([
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'background' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_background' => ['nullable', 'boolean'],
        ]);

        abort_unless(
            $request->hasFile('logo')
            || $request->hasFile('background')
            || ! empty($data['remove_logo'])
            || ! empty($data['remove_background']),
            422,
            'Envie uma logo/background ou marque uma mídia para remoção.'
        );

        $before = $this->mediaSnapshot($establishment);
        $actorId = (int) $request->user()->id;

        DB::transaction(function () use ($request, $data, $establishment, $actorId) {
            foreach (['logo', 'background'] as $type) {
                $remove = (bool) ($data['remove_' . $type] ?? false);
                if (! $remove && ! $request->hasFile($type)) {
                    continue;
                }

                $this->deleteType($establishment, $type);

                if ($request->hasFile($type)) {
                    File::storeOne(
                        $request->file($type),
                        'establishment',
                        (int) $establishment->id,
                        $type,
                        $establishment->app_id ? (int) $establishment->app_id : null,
                        $actorId
                    );
                }
            }
        });

        $fresh = $establishment->fresh()->load([
            'app:id,name,slug',
            'applications:id,name,slug',
            'user:id,first_name,last_name,email',
            'files' => fn ($files) => $files
                ->whereIn('type', ['logo', 'avatar', 'image', 'background'])
                ->orderBy('position'),
        ]);

        EcosystemAuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'establishment.media_updated',
            'entity_type' => Establishment::class,
            'entity_id' => $establishment->id,
            'before' => $before,
            'after' => $this->mediaSnapshot($fresh),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);

        return response()->json([
            'message' => 'Logo e background atualizados com sucesso.',
            'establishment' => $fresh,
        ]);
    }

    private function mediaSnapshot(Establishment $establishment): array
    {
        $files = $establishment->relationLoaded('files') ? $establishment->files : $establishment->files()->get();

        return collect(['logo', 'background'])->mapWithKeys(function ($type) use ($files) {
            $file = $files->firstWhere('type', $type);
            return [$type => $file ? [
                'id' => $file->id,
                'public_url' => $file->public_url,
                'original_name' => $file->original_name,
            ] : null];
        })->all();
    }

    private function deleteType(Establishment $establishment, string $type): void
    {
        foreach ($establishment->files()->where('type', $type)->get() as $file) {
            if ($file->storage !== 'external' && $file->path) {
                Storage::disk('public')->delete($file->path);
            }
            $file->delete();
        }
    }
}
