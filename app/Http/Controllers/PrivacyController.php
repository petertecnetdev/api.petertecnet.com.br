<?php

namespace App\Http\Controllers;

use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PrivacyController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function export(Request $request)
    {
        $this->context->requireCapability('privacy');
        $userId = (int) $request->user()->id;
        $profile = DB::table('laora_profiles')->where('user_id', $userId)->first();
        $photos = $profile ? DB::table('laora_photos')->where('profile_id', $profile->id)->orderBy('position')->get()->map(function ($photo) {
            return [
                'id' => $photo->id,
                'url' => Storage::disk('public')->url($photo->path),
                'position' => $photo->position,
                'is_primary' => (bool) $photo->is_primary,
                'moderation_status' => $photo->moderation_status,
                'created_at' => $photo->created_at,
            ];
        }) : collect();

        return response()->json(['data' => [
            'generated_at' => now()->toIso8601String(),
            'profile' => $profile,
            'photos' => $photos,
            'swipes' => DB::table('laora_swipes')->where('swiper_user_id', $userId)->get(),
            'matches' => DB::table('laora_matches')->where('user_one_id', $userId)->orWhere('user_two_id', $userId)->get(),
            'messages' => DB::table('laora_messages')->where('sender_user_id', $userId)->get(),
            'blocks' => DB::table('laora_blocks')->where('blocker_user_id', $userId)->get(),
            'reports_submitted' => DB::table('laora_reports')->where('reporter_user_id', $userId)->get(),
            'moderation_actions' => DB::table('laora_moderation_actions')->where('target_user_id', $userId)->get(),
        ]]);
    }

    public function destroyProfile(Request $request)
    {
        $this->context->requireCapability('privacy');
        $userId = (int) $request->user()->id;
        $request->validate(['confirmation' => ['required', 'in:EXCLUIR']]);
        $profile = DB::table('laora_profiles')->where('user_id', $userId)->first();
        if (! $profile) return response()->json(['message' => 'Seu perfil já não existe.']);
        $paths = DB::table('laora_photos')->where('profile_id', $profile->id)->pluck('path')->all();

        DB::transaction(function () use ($userId, $profile) {
            DB::table('laora_messages')->where('sender_user_id', $userId)->update(['body' => '[mensagem removida pelo titular]', 'deleted_at' => now(), 'updated_at' => now()]);
            DB::table('laora_matches')->where(fn ($q) => $q->where('user_one_id', $userId)->orWhere('user_two_id', $userId))->update(['status' => 'unmatched', 'unmatched_at' => now(), 'unmatched_by_user_id' => $userId, 'updated_at' => now()]);
            DB::table('laora_swipes')->where('swiper_user_id', $userId)->orWhere('target_user_id', $userId)->delete();
            DB::table('laora_blocks')->where('blocker_user_id', $userId)->orWhere('blocked_user_id', $userId)->delete();
            DB::table('laora_profiles')->where('id', $profile->id)->delete();
        });

        foreach ($paths as $path) Storage::disk('public')->delete($path);
        return response()->json(['message' => 'Seu perfil e dados de relacionamento foram removidos. Registros mínimos de segurança e moderação podem ser preservados quando houver obrigação legal, prevenção a fraude ou interesse legítimo aplicável.']);
    }
}
