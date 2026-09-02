<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LaoraPrivacyController extends Controller
{
    public function export(Request $request)
    {
        $userId = (int) $request->user()->id;
        $profile = DB::table('laora_profiles')->where('user_id', $userId)->first();

        $data = [
            'generated_at' => now()->toIso8601String(),
            'profile' => $profile,
            'photos' => $profile ? DB::table('laora_photos')->where('profile_id', $profile->id)->get() : [],
            'swipes' => DB::table('laora_swipes')->where('swiper_user_id', $userId)->get(),
            'matches' => DB::table('laora_matches')->where('user_one_id', $userId)->orWhere('user_two_id', $userId)->get(),
            'messages' => DB::table('laora_messages')->where('sender_user_id', $userId)->get(),
            'blocks' => DB::table('laora_blocks')->where('blocker_user_id', $userId)->get(),
            'reports_submitted' => DB::table('laora_reports')->where('reporter_user_id', $userId)->get(),
            'moderation_actions' => DB::table('laora_moderation_actions')->where('target_user_id', $userId)->get(),
        ];

        return response()->json(['data' => $data]);
    }

    public function destroyProfile(Request $request)
    {
        $userId = (int) $request->user()->id;
        $data = $request->validate(['confirmation' => ['required', 'in:EXCLUIR']]);
        unset($data);

        $profile = DB::table('laora_profiles')->where('user_id', $userId)->first();
        if (! $profile) {
            return response()->json(['message' => 'Seu perfil do Laora já não existe.']);
        }

        $paths = DB::table('laora_photos')->where('profile_id', $profile->id)->pluck('path')->all();

        DB::transaction(function () use ($userId, $profile) {
            DB::table('laora_messages')->where('sender_user_id', $userId)->update([
                'body' => '[mensagem removida pelo titular]',
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('laora_matches')->where('user_one_id', $userId)->orWhere('user_two_id', $userId)->delete();
            DB::table('laora_swipes')->where('swiper_user_id', $userId)->orWhere('target_user_id', $userId)->delete();
            DB::table('laora_blocks')->where('blocker_user_id', $userId)->orWhere('blocked_user_id', $userId)->delete();
            DB::table('laora_profiles')->where('id', $profile->id)->delete();
        });

        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }

        return response()->json([
            'message' => 'Seu perfil e dados de relacionamento foram removidos. Registros mínimos de segurança e moderação podem ser preservados quando houver obrigação ou interesse legítimo aplicável.',
        ]);
    }
}
